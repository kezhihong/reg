<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\AuditAction;
use App\Constants\KycLevel;
use App\Constants\LoginType;
use App\Constants\OAuthProvider;
use App\Constants\UserStatus;
use App\Exception\BusinessException;
use App\Model\OauthState;
use App\Model\User;
use App\Model\UserIdentity;
use App\Provider\OAuthProviderInterface;
use App\Util\CryptoService;
use App\Util\RequestContext;
use App\Util\SecurityUtil;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

/**
 * OAuth 服务（docs/01 §5.1 / docs/02 §3）：
 * state 只存哈希 + 一次性消费 + TTL 5 分钟 + PKCE(S256)；
 * redirect_uri 白名单防开放重定向；回调验签/防伪造（T7/T8）。
 */
class OAuthService
{
    public function __construct(
        protected ContainerInterface $container,
        protected CryptoService $cryptoService,
        protected AuthService $authService,
        protected AuditService $auditService,
        protected RequestInterface $request,
        protected ResponseInterface $response
    ) {
    }

    /**
     * 3.1 发起授权（docs/02 §3.1）：生成 state（只存哈希），302 第三方授权页。
     *
     * @return array{redirect_url:string}
     */
    public function authorize(string $provider, string $redirectUri, ?int $loginUserId = null): array
    {
        $this->assertProvider($provider);
        // 凭据未配置（未申请第三方 OAuth App）→ 明确提示，不走 mock（docs/05 §3.3）
        $this->assertProviderConfigured($provider);
        $this->assertRedirectAllowed($redirectUri);

        $state = SecurityUtil::salt() . bin2hex(random_bytes(24)); // 明文 = 盐(16 hex) + 随机
        $salt = SecurityUtil::extractSalt($state);
        $codeVerifier = null;
        if ($provider === OAuthProvider::GOOGLE) {
            $codeVerifier = $this->generatePkceVerifier();
        }

        $now = time();
        $stateRow = new OauthState();
        // 盐嵌入 state 明文前缀（docs/03 §3.6 表无 salt 列），哈希按前缀盐计算
        $stateRow->state_hash = SecurityUtil::hash($salt, $state);
        $stateRow->code_verifier_encrypted = $codeVerifier !== null ? $this->cryptoService->encrypt($codeVerifier) : '';
        $stateRow->provider = $provider;
        $stateRow->user_id = $loginUserId ?? 0; // 绑定场景绑定 user_id（防 CSRF 式绑定，docs/04 §5.4）
        $stateRow->redirect_uri = $redirectUri;
        $stateRow->expires_at = $now + 300;
        $stateRow->consumed_at = 0;
        $stateRow->created_at = $now;
        $stateRow->updated_at = $now;
        $stateRow->is_deleted = 0;
        $stateRow->save();

        $oauthProvider = $this->getProvider($provider);
        $url = $oauthProvider->authorizeUrl([
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        return ['redirect_url' => $url];
    }

    /**
     * 3.2 回调（docs/02 §3.2）：验 state（哈希 + 一次性）→ code 换 token → 拉用户 →
     * 查绑定（有则登录；无则按登录/绑定场景建号或绑定）。
     *
     * @return array{redirect_url:string} 302 目标（成功 ?code=0，失败 ?code=错误码）
     */
    public function callback(string $provider, string $code, string $state): array
    {
        $this->assertProvider($provider);
        $stateRow = null;

        try {
            $stateRow = $this->consumeState($state);
            if ($stateRow->provider !== $provider) {
                throw new BusinessException(AppErrorCode::OAUTH_STATE_INVALID);
            }

            $oauthProvider = $this->getProvider($provider);
            $codeVerifier = '';
            if ((string) $stateRow->code_verifier_encrypted !== '') {
                $codeVerifier = $this->cryptoService->decrypt((string) $stateRow->code_verifier_encrypted);
            }
            $userInfo = $oauthProvider->getUserByCode([
                'code' => $code,
                'redirect_uri' => (string) $stateRow->redirect_uri,
                'code_verifier' => $codeVerifier,
            ]);

            $identity = UserIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $userInfo['provider_user_id'])
                ->where('is_deleted', 0)
                ->first();

            if ($identity !== null) {
                // 已绑定 → 正常登录（docs/02 §3.2）
                $user = User::query()->find((int) $identity->user_id);
                if ($user === null || (int) $user->is_deleted === 1 || (int) $user->status === UserStatus::DISABLED) {
                    throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
                }
                $identity->last_used_at = time();
                $identity->updated_at = time();
                $identity->save();

                return $this->loginOrTotp($user, $provider, $stateRow, false);
            }

            $bindUserId = (int) $stateRow->user_id;
            if ($bindUserId > 0) {
                // 登录态绑定场景：建立绑定（防重复绑定）→ 绑定成功（code=0）
                $this->bindIdentity($bindUserId, $provider, $userInfo);
                return $this->redirect((string) $stateRow->redirect_uri, 0, 0);
            }

            // 未绑定 + 未登录 → 自动建号（docs/02 §3.2）
            $user = $this->createUserFromOAuth($provider, $userInfo);
            $this->bindIdentity((int) $user->id, $provider, $userInfo);
            return $this->loginOrTotp($user, $provider, $stateRow, true);
        } catch (BusinessException $e) {
            return $this->redirect((string) ($stateRow->redirect_uri ?? ''), $e->getErrorCode(), 1);
        }
    }

    /**
     * 3.3 登录态绑定（docs/02 §3.3）：已绑定该第三方 → 11103。
     */
    public function bind(string $provider, string $redirectUri, int $userId): array
    {
        $this->assertProvider($provider);
        $this->assertRedirectAllowed($redirectUri);

        $exists = UserIdentity::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('is_deleted', 0)
            ->exists();
        if ($exists) {
            throw new BusinessException(AppErrorCode::OAUTH_ALREADY_BOUND);
        }

        return $this->authorize($provider, $redirectUri, $userId);
    }

    /**
     * 3.4 解绑（docs/02 §3.4）：若用户无密码无手机无邮箱且该第三方是唯一登录凭证 → 拒绝（11104）。
     */
    public function unbind(string $provider, int $userId): void
    {
        $this->assertProvider($provider);
        $identity = UserIdentity::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('is_deleted', 0)
            ->first();
        if ($identity === null) {
            throw new BusinessException(AppErrorCode::NOT_FOUND);
        }

        $user = User::query()->find($userId);
        $hasOtherCredential = $user !== null
            && ((string) $user->password_hash !== ''
                || (string) $user->phone !== ''
                || (string) $user->email !== '');
        $otherIdentity = UserIdentity::query()
            ->where('user_id', $userId)
            ->where('provider', '<>', $provider)
            ->where('is_deleted', 0)
            ->exists();

        if (! $hasOtherCredential && ! $otherIdentity) {
            throw new BusinessException(AppErrorCode::OAUTH_UNBIND_DENIED);
        }

        $identity->is_deleted = 1;
        $identity->updated_at = time();
        $identity->save();
        $this->auditService->audit(AuditAction::OAUTH_UNBIND, $userId, 'user', ['provider' => $provider]);
    }

    /**
     * 查询绑定列表（前端「安全设置」展示）。
     *
     * @return array<int,array{provider:string,provider_email:string,last_used_at:int}>
     */
    public function listBound(int $userId): array
    {
        return UserIdentity::query()
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->get()
            ->map(fn (UserIdentity $i) => [
                'provider' => (string) $i->provider,
                'provider_email' => (string) $i->provider_email,
                'last_used_at' => (int) $i->last_used_at,
            ])
            ->values()
            ->toArray();
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, OAuthProvider::ALL, true)) {
            throw new BusinessException(AppErrorCode::NOT_FOUND, '不支持的第三方渠道');
        }
    }

    /**
     * 第三方 OAuth App 凭据门禁：未配置 OAUTH_*_CLIENT_ID 时提示暂未开通
     * （申请 GitHub OAuth App / Google OAuth Client 后配置环境变量即可启用）。
     */
    private function assertProviderConfigured(string $provider): void
    {
        $envKey = $provider === OAuthProvider::GITHUB
            ? 'OAUTH_GITHUB_CLIENT_ID'
            : 'OAUTH_GOOGLE_CLIENT_ID';
        if ((string) env($envKey, '') === '') {
            throw new BusinessException(
                AppErrorCode::BUSINESS_RULE,
                '第三方登录暂未开通'
            );
        }
    }

    /**
     * redirect_uri 白名单（docs/04 §5.3 T7）：OAUTH_REDIRECT_WHITELIST 精确前缀匹配。
     */
    private function assertRedirectAllowed(string $redirectUri): void
    {
        $whitelist = array_filter(array_map('trim', explode(',', (string) env('OAUTH_REDIRECT_WHITELIST', ''))));
        if ($whitelist === []) {
            $whitelist = ['http://localhost:8080'];
        }
        foreach ($whitelist as $allowed) {
            if (str_starts_with($redirectUri, $allowed)) {
                return;
            }
        }
        throw new BusinessException(AppErrorCode::PARAM_INVALID, '回调地址不在白名单内');
    }

    private function getProvider(string $provider): OAuthProviderInterface
    {
        $class = $provider === OAuthProvider::GITHUB
            ? \App\Provider\GithubOAuthProvider::class
            : \App\Provider\GoogleOAuthProvider::class;
        return $this->container->get($class);
    }

    private function generatePkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * 消费 state：哈希比对 + 一次性条件更新（docs/04 §5.4 T8）。
     */
    private function consumeState(string $state): OauthState
    {
        $salt = substr($state, 0, 16); // state 明文 = 盐(16 hex) + 随机
        $rows = OauthState::query()
            ->where('state_hash', SecurityUtil::hash($salt, $state))
            ->where('expires_at', '>', time())
            ->where('consumed_at', 0)
            ->limit(1)
            ->get();
        if ($rows->isEmpty()) {
            throw new BusinessException(AppErrorCode::OAUTH_STATE_INVALID);
        }
        $row = $rows->first();
        // 二次比对：盐从 state 明文前缀提取（表无 salt 列，与写入时口径一致）
        if (! SecurityUtil::equals($row->state_hash, SecurityUtil::hash($salt, $state))) {
            throw new BusinessException(AppErrorCode::OAUTH_STATE_INVALID);
        }
        $updated = OauthState::query()
            ->where('id', $row->id)
            ->where('consumed_at', 0)
            ->update(['consumed_at' => time(), 'updated_at' => time()]);
        if ($updated === 0) {
            throw new BusinessException(AppErrorCode::OAUTH_STATE_INVALID);
        }
        return $row;
    }

    private function bindIdentity(int $userId, string $provider, array $userInfo): void
    {
        try {
            $identity = new UserIdentity();
            $identity->user_id = $userId;
            $identity->provider = $provider;
            $identity->provider_user_id = $userInfo['provider_user_id'];
            $identity->provider_email = $userInfo['email'] ?? '';
            $identity->access_token_encrypted = '';
            $identity->refresh_token_encrypted = '';
            $identity->scopes = '';
            $identity->last_used_at = time();
            $identity->created_at = time();
            $identity->updated_at = time();
            $identity->is_deleted = 0;
            $identity->save();
            $this->auditService->audit(AuditAction::OAUTH_BIND, $userId, 'user', ['provider' => $provider]);
        } catch (\Throwable) {
            // 唯一约束（provider+provider_user_id）冲突
            throw new BusinessException(AppErrorCode::OAUTH_ALREADY_BOUND);
        }
    }

    private function createUserFromOAuth(string $provider, array $userInfo): User
    {
        $now = time();
        $user = new User();
        $user->username = null;
        $user->email = ! empty($userInfo['email']) ? $userInfo['email'] : null;
        $user->password_hash = '';
        $user->country_code = null;
        $user->phone = null;
        $user->status = UserStatus::NORMAL;
        $user->is_email_verified = 0;
        $user->is_phone_verified = 0;
        $user->login_failed_count = 0;
        $user->locked_until = 0;
        $user->token_version = 0;
        $user->totp_secret_encrypted = '';
        $user->totp_enabled = 0;
        $user->kyc_level = KycLevel::L0;
        $user->is_admin = 0;
        $user->last_login_at = 0;
        $user->last_login_ip = '';
        $user->created_at = $now;
        $user->updated_at = $now;
        $user->is_deleted = 0;
        $user->save();
        return $user;
    }

    /**
     * OAuth 登录（2FA 判断）或绑定成功页。
     */
    private function loginOrTotp(User $user, string $provider, OauthState $stateRow, bool $isNew): array
    {
        if ((int) $user->totp_enabled === 1) {
            // 2FA：需要前端续走二次验证（OAuth 场景简化：返回票据由前端走 /2fa/login/verify）
            $device = $this->authService->deviceService()->getOrCreateDevice((int) $user->id);
            $ticket = $this->authService->ticketService()->issue((int) $user->id, (int) $device['device']->id);
            return $this->redirect((string) $stateRow->redirect_uri, 0, 0, [
                'need_totp' => 1,
                'totp_ticket' => $ticket,
                'cookies' => [$this->authService->deviceService()->deviceCookie($device['deviceKey'])],
            ]);
        }

        $session = $this->authService->establishSessionPublic(
            $user,
            $provider === OAuthProvider::GITHUB ? LoginType::GITHUB : LoginType::GOOGLE,
            $isNew
        );
        return $this->redirect((string) $stateRow->redirect_uri, 0, 0, $session);
    }

    private function redirect(string $redirectUri, int $code, int $error, array $extra = []): array
    {
        // cookies 剥离：不进入 URL 查询参数，由 Controller 在 302 响应上种 Set-Cookie
        $cookies = $extra['cookies'] ?? [];
        unset($extra['cookies']);
        $query = http_build_query(array_merge(['code' => $code, 'error' => $error], $extra));
        return [
            'redirect_url' => $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query,
            'cookies' => $cookies,
        ];
    }
}
