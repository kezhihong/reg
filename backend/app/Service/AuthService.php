<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\AuditAction;
use App\Constants\CookieNames;
use App\Constants\KycLevel;
use App\Constants\LoginFailReason;
use App\Constants\LoginType;
use App\Constants\RefreshRevokedReason;
use App\Constants\UserStatus;
use App\Constants\VerificationScene;
use App\Exception\BusinessException;
use App\Event\LoginSucceeded;
use App\Model\RefreshToken;
use App\Model\User;
use App\Model\UserDevice;
use App\Util\GeoIpService;
use App\Util\JwtService;
use App\Util\RequestContext;
use App\Util\SecurityUtil;
use App\Util\TicketService;
use App\Util\UserAgentParser;
use Hyperf\DbConnection\Db;
use Psr\EventDispatcher\EventDispatcherInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * 认证服务（docs/01 §3.2 / §4）：登录/注册/登出/刷新/改密/重置，会话与设备编排。
 * 事务内禁止远程调用；登录日志与失败计数同事务（D8）。
 */
class AuthService
{
    public function __construct(
        protected JwtService $jwtService,
        protected TicketService $ticketService,
        protected DeviceService $deviceService,
        protected VerificationCodeService $codeService,
        protected AuditService $auditService,
        protected GeoIpService $geoIpService,
        protected EventDispatcherInterface $dispatcher,
        protected RequestInterface $request,
        protected ResponseInterface $response
    ) {
    }

    // ------------------------------------------------------------------
    // 注册 / 登录
    // ------------------------------------------------------------------

    /**
     * 2.1 注册（docs/02 §2.1）：唯一性由 DB 唯一约束兜底；注册成功即建立登录态。
     */
    public function register(array $input): array
    {
        $now = time();
        $hasPhone = ! empty($input['phone']) && ! empty($input['code']);

        // 手机号绑定验证码校验（scene=1 登录注册二合一）
        if ($hasPhone) {
            $this->codeService->verify(
                (string) $input['country_code'],
                (string) $input['phone'],
                VerificationScene::LOGIN_REGISTER,
                (string) $input['code']
            );
        }

        try {
            $user = new User();
            $user->username = $input['username'] ?? null;
            $user->email = $input['email'] ?? null;
            $user->password_hash = password_hash((string) $input['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $user->country_code = $hasPhone ? (string) $input['country_code'] : null;
            $user->phone = $hasPhone ? (string) $input['phone'] : null;
            $user->status = UserStatus::NORMAL;
            $user->is_email_verified = 0;
            $user->is_phone_verified = $hasPhone ? 1 : 0;
            $user->login_failed_count = 0;
            $user->locked_until = 0;
            $user->token_version = 0;
            $user->totp_secret_encrypted = '';
            $user->totp_enabled = 0;
            $user->kyc_level = $hasPhone ? KycLevel::L1 : KycLevel::L0;
            $user->is_admin = 0;
            $user->last_login_at = 0;
            $user->last_login_ip = '';
            $user->created_at = $now;
            $user->updated_at = $now;
            $user->is_deleted = 0;
            $user->save();
        } catch (\Throwable $e) {
            // 唯一约束冲突 → 409（docs/02 §1.3 16101）
            throw new BusinessException(AppErrorCode::USER_TAKEN, null, ['field' => 'username/email/phone']);
        }

        $this->auditService->audit(AuditAction::LOGIN_SUCCESS, (int) $user->id, 'user', [
            'login_type' => LoginType::PASSWORD,
            'is_new' => true,
        ]);

        // 建立登录态（设备归并 + 异地判定 + 发 Cookie + 登录日志 + 事件）
        $session = $this->establishSession($user, LoginType::PASSWORD, true);

        return [
            'user' => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'phone' => $user->phone ? \App\Util\Masker::phone((string) $user->phone) : '',
                'kyc_level' => (int) $user->kyc_level,
                'totp_enabled' => (int) $user->totp_enabled === 1,
            ],
            'is_new' => true,
        ] + $session;
    }

    /**
     * 2.2 账号/邮箱/手机号 + 密码登录（docs/02 §2.2）：统一文案防枚举 + 假哈希抹平时序。
     * account 支持：用户名 / 邮箱 / 手机号（纯数字按默认区号 LOGIN_DEFAULT_COUNTRY_CODE，
     * 或 +8613800000000 格式自带区号）——三合一入口，服务端分别查唯一索引。
     */
    public function login(string $account, string $password): array
    {
        // 统一走唯一索引（username / email / phone），不区分入口
        $user = User::query()
            ->where('is_deleted', 0)
            ->where(function ($q) use ($account) {
                $q->where('username', $account)
                    ->orWhere('email', $account);
                // 手机号：+区号号码 或 纯数字（默认区号）
                if (preg_match('/^\+(\d{1,4})(\d{11,15})$/', $account, $m)) {
                    $q->orWhere(function ($qq) use ($m) {
                        $qq->where('country_code', '+' . $m[1])->where('phone', $m[2]);
                    });
                } elseif (preg_match('/^\d{11,15}$/', $account)) {
                    $q->orWhere(function ($qq) use ($account) {
                        $qq->where('country_code', (string) env('LOGIN_DEFAULT_COUNTRY_CODE', '+86'))
                            ->where('phone', $account);
                    });
                }
            })
            ->first();

        // 锁定检查在密码验证前（docs/04 §4.1：锁定仅对确证存在的账号以 423 表达）
        if ($user !== null) {
            $this->assertLoginAllowed($user);
        }

        // 防枚举：账号不存在也执行假 bcrypt 校验，抹平时序差异（docs/04 §2.1）
        $hash = $user?->password_hash ?? $this->fakeHash();
        $passwordOk = $user !== null && $hash !== '' && password_verify($password, $hash);

        if ($user === null || ! $passwordOk) {
            $this->writeFailedLoginLog($user, LoginType::PASSWORD, LoginFailReason::PASSWORD_WRONG);
            if ($user !== null) {
                $this->recordLoginFailure((int) $user->id, 10, LoginType::PASSWORD);
            }
            throw new BusinessException(AppErrorCode::AUTH_BAD_CREDENTIALS);
        }

        $this->assertLoginAllowed($user);

        // 2FA 已启用：返回「需二次验证」票据（不建登录态，docs/02 §2.2）
        if ((int) $user->totp_enabled === 1) {
            $device = $this->deviceService->getOrCreateDevice((int) $user->id);
            return [
                'need_totp' => true,
                'totp_ticket' => $this->ticketService->issue((int) $user->id, (int) $device['device']->id),
                'cookies' => [$this->deviceService->deviceCookie($device['deviceKey'])],
            ];
        }

        $session = $this->establishSession($user, LoginType::PASSWORD, false);
        return ['need_totp' => false] + $session;
    }

    /**
     * 2.4 短信验证码登录（注册二合一，docs/02 §2.4）。
     */
    public function smsLogin(string $countryCode, string $phone, string $code): array
    {
        $this->codeService->verify($countryCode, $phone, VerificationScene::LOGIN_REGISTER, $code);

        $user = User::query()
            ->where('country_code', $countryCode)
            ->where('phone', $phone)
            ->where('is_deleted', 0)
            ->first();

        $isNew = false;
        if ($user === null) {
            $user = $this->createUserFromPhone($countryCode, $phone);
            $isNew = true;
        }

        $this->assertLoginAllowed($user);

        if ((int) $user->totp_enabled === 1) {
            $device = $this->deviceService->getOrCreateDevice((int) $user->id);
            return [
                'need_totp' => true,
                'totp_ticket' => $this->ticketService->issue((int) $user->id, (int) $device['device']->id),
                'cookies' => [$this->deviceService->deviceCookie($device['deviceKey'])],
            ];
        }

        $session = $this->establishSession($user, LoginType::SMS, $isNew);
        return ['need_totp' => false, 'is_new' => $isNew] + $session;
    }

    /**
     * 2FA 登录二次验证通过后建立完整登录态（docs/02 §4.4 调用于 TotpService）。
     */
    public function completeTotpLogin(int $userId, int $deviceId): array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }
        $this->assertLoginAllowed($user);

        $device = UserDevice::query()
            ->where('id', $deviceId)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if ($device === null || (int) $device->revoked_at > 0) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        $session = $this->establishSession($user, LoginType::PASSWORD, false, $device);
        return ['need_totp' => false] + $session;
    }

    // ------------------------------------------------------------------
    // 刷新 / 登出 / 全局吊销
    // ------------------------------------------------------------------

    /**
     * 2.5 刷新令牌（轮换，docs/01 §4.2 D1）：旧行 rotated_at 条件更新 + 新行；
     * 并发刷新同设备同 IP 放行；异设备/异 IP 判重用 → 吊销设备全部会话。
     */
    public function refresh(): array
    {
        $cookies = $this->request->getCookieParams();
        $rawToken = (string) ($cookies[CookieNames::REFRESH] ?? '');
        if ($rawToken === '') {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        // 查哈希行（O(1)：盐嵌入 token 前缀，uk_refresh_token_hash 直查，docs/03 §3.4）
        $salt = SecurityUtil::extractSalt($rawToken);
        $row = RefreshToken::query()
            ->where('token_hash', SecurityUtil::hash($salt, $rawToken))
            ->first();

        if ($row === null || ! SecurityUtil::equals($row->token_hash, SecurityUtil::hash($row->salt, $rawToken))) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        // 已吊销（登出/踢/重用/全局吊销）→ 401
        if ((int) $row->revoked_at > 0) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        $user = User::query()->find((int) $row->user_id);
        if ($user === null || (int) $user->is_deleted === 1 || (int) $user->status === UserStatus::DISABLED) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        // 轮换事务：FOR UPDATE 设备行串行化并发刷新（docs/01 §4.2）
        return Db::transaction(function () use ($row, $user, $rawToken) {
            $device = UserDevice::query()
                ->where('id', (int) $row->device_id)
                ->where('user_id', (int) $row->user_id)
                ->lockForUpdate()
                ->first();

            if ($device === null || (int) $device->revoked_at > 0) {
                throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
            }

            // 重载当前行（事务内读最新状态）
            $current = RefreshToken::query()->find((int) $row->id);
            if ($current === null) {
                throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
            }

            if ((int) $current->rotated_at === 0) {
                // 正常轮换：旧行条件更新 rotated_at + 签发新行
                $cookies = $this->rotateToken($current, $device, $user);
            } else {
                // 已轮换：同设备且同 IP → 并发重试放行；否则 → 重用检测
                $sameIp = RequestContext::ip() === (string) $device->last_ip
                    || (string) $device->last_ip === '';
                if (! $sameIp) {
                    $this->revokeDeviceSession($device, RefreshRevokedReason::REUSE_DETECTED, $user);
                    $this->auditService->audit(AuditAction::REFRESH_REUSE_DETECTED, (int) $device->id, 'device', [
                        'user_id' => (int) $user->id,
                    ]);
                    throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
                }
                // 并发重试：以设备当前有效行链式轮换，保证每次刷新都返回新会话
                $active = RefreshToken::query()
                    ->where('device_id', (int) $device->id)
                    ->where('rotated_at', 0)
                    ->where('revoked_at', 0)
                    ->where('expires_at', '>', time())
                    ->orderByDesc('id')
                    ->first();
                if ($active === null) {
                    throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
                }
                $cookies = $this->rotateToken($active, $device, $user);
            }

            // 更新设备活跃信息（docs/04 §3.4）
            $device->last_active_at = time();
            $device->last_ip = RequestContext::ip();
            $device->updated_at = time();
            $device->save();

            return [
                'user' => [
                    'id' => (int) $user->id,
                    'username' => (string) $user->username,
                ],
                'cookies' => $cookies ?? [],
            ];
        });
    }

    /**
     * 2.6 登出当前设备（docs/02 §2.6）：吊销该设备全部 refresh（reason=登出）。
     */
    public function logout(int $deviceId): void
    {
        $now = time();
        RefreshToken::query()
            ->where('device_id', $deviceId)
            ->where('revoked_at', 0)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => RefreshRevokedReason::LOGOUT,
                'updated_at' => $now,
            ]);
        $this->auditService->audit(AuditAction::LOGOUT, $deviceId, 'device');
    }

    /**
     * 2.7 登出全部设备（docs/02 §2.7）：吊销全部设备 + token_version+1（Access 立即失效）。
     */
    public function logoutAll(int $userId): void
    {
        $now = time();
        Db::table('user_devices')
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
        RefreshToken::query()
            ->where('user_id', $userId)
            ->where('revoked_at', 0)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => RefreshRevokedReason::GLOBAL_REVOKE,
                'updated_at' => $now,
            ]);
        $this->bumpTokenVersion($userId);
        $this->auditService->audit(AuditAction::LOGOUT_ALL, $userId, 'user');
    }

    /**
     * 全局吊销：token_version+1（docs/01 §4.2，触发点：改密/重置/关 2FA/全部下线/禁号）。
     */
    public function bumpTokenVersion(int $userId): void
    {
        Db::table('users')->where('id', $userId)->increment('token_version', 1, ['updated_at' => time()]);
    }

    // ------------------------------------------------------------------
    // 密码重置 / 修改
    // ------------------------------------------------------------------

    /**
     * 2.8 发送重置凭证（docs/02 §2.8）：账号不存在也返回成功（防枚举）。
     * 手机号（含区号）走短信（scene=3）；邮箱走邮件。
     */
    public function forgotPassword(string $account): array
    {
        $user = $this->findUserByAccount($account);
        if ($user === null) {
            // 防枚举：不暴露账号存在性，仍返回成功（不实际发码）
            return ['ttl' => 300, 'sent' => false];
        }

        if ($user->phone !== '' && $user->country_code !== '' && str_contains($account, '+')) {
            $this->codeService->send((string) $user->country_code, (string) $user->phone, VerificationScene::RESET_PASSWORD);
            return ['ttl' => 300, 'sent' => true, 'channel' => 'sms'];
        }

        // 邮箱通道：dev 环境仅记录（真实 SMTP 由 EmailProvider 承担）
        if ($user->email !== '') {
            $code = SecurityUtil::code();
            $salt = SecurityUtil::salt();
            Db::table('verification_codes')->insert([
                'scene' => VerificationScene::RESET_PASSWORD,
                'country_code' => '',
                'phone' => '',
                'email' => (string) $user->email,
                'code_hash' => SecurityUtil::hash($salt, $code),
                'salt' => $salt,
                'expires_at' => time() + 300,
                'max_attempts' => 5,
                'attempts' => 0,
                'consumed_at' => 0,
                'request_ip' => RequestContext::ip(),
                'created_at' => time(),
                'updated_at' => time(),
                'is_deleted' => 0,
            ]);
            \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\App\Provider\EmailProviderInterface::class)
                ->send((string) $user->email, '重置密码验证码', '您的重置密码验证码为：' . $code . '，5 分钟内有效。');
            return ['ttl' => 300, 'sent' => true, 'channel' => 'email'];
        }

        return ['ttl' => 300, 'sent' => false];
    }

    /**
     * 2.9 重置密码（docs/02 §2.9）：验证码校验 → 改密 → 全局吊销（不建立登录态）。
     */
    public function resetPassword(string $account, string $code, string $newPassword): void
    {
        $user = $this->findUserByAccount($account);
        if ($user === null) {
            throw new BusinessException(AppErrorCode::AUTH_RESET_INVALID);
        }

        if ($user->phone !== '' && $user->country_code !== '' && str_contains($account, '+')) {
            $this->codeService->verify((string) $user->country_code, (string) $user->phone, VerificationScene::RESET_PASSWORD, $code);
        } else {
            $this->verifyEmailCode((string) $user->email, $code);
        }

        $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $user->updated_at = time();
        $user->save();

        // 全局吊销：全部设备下线
        $now = time();
        Db::table('user_devices')->where('user_id', $user->id)->update(['revoked_at' => $now, 'updated_at' => $now]);
        RefreshToken::query()->where('user_id', $user->id)->where('revoked_at', 0)
            ->update(['revoked_at' => $now, 'revoked_reason' => RefreshRevokedReason::PASSWORD_CHANGED, 'updated_at' => $now]);
        $this->bumpTokenVersion((int) $user->id);

        $this->auditService->audit(AuditAction::PASSWORD_RESET, (int) $user->id, 'user');
    }

    /**
     * 2.10 修改密码（docs/02 §2.10）：验原密码 → 改密 → 全局吊销（需重新登录）。
     */
    public function changePassword(User $user, string $oldPassword, string $newPassword): void
    {
        if ((string) $user->password_hash === '') {
            throw new BusinessException(AppErrorCode::AUTH_OLD_PASSWORD_WRONG, '未设置密码，请先绑定手机号后重置');
        }
        if (! password_verify($oldPassword, (string) $user->password_hash)) {
            throw new BusinessException(AppErrorCode::AUTH_OLD_PASSWORD_WRONG);
        }

        $user->password_hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $user->updated_at = time();
        $user->save();

        $now = time();
        Db::table('user_devices')->where('user_id', $user->id)->update(['revoked_at' => $now, 'updated_at' => $now]);
        RefreshToken::query()->where('user_id', $user->id)->where('revoked_at', 0)
            ->update(['revoked_at' => $now, 'revoked_reason' => RefreshRevokedReason::PASSWORD_CHANGED, 'updated_at' => $now]);
        $this->bumpTokenVersion((int) $user->id);

        $this->auditService->audit(AuditAction::PASSWORD_CHANGED, (int) $user->id, 'user');
    }

    // ------------------------------------------------------------------
    // 公开访问器（供 OAuth/KYC 等服务协作调用，避免循环依赖）
    // ------------------------------------------------------------------

    public function deviceService(): DeviceService
    {
        return $this->deviceService;
    }

    public function ticketService(): TicketService
    {
        return $this->ticketService;
    }

    /**
     * OAuth 登录场景的会话建立（docs/02 §3.2 已绑定/自动建号后）。
     */
    public function establishSessionPublic(User $user, int $loginType, bool $isNew): array
    {
        return $this->establishSession($user, $loginType, $isNew);
    }

    // ------------------------------------------------------------------
    // 失败计数 / 锁定（docs/04 §4.1）
    // ------------------------------------------------------------------

    /**
     * 失败计数原子化 + 自动锁定（docs/04 §4.1）：达阈值 → locked_until = now+900。
     * 锁定解除后计数归零重新计；连续锁定不延长窗口。
     *
     * @param int $threshold 密码类 10 / 2FA 类 5（docs/04 §4.3）
     */
    public function recordLoginFailure(int $userId, int $threshold, int $loginType): void
    {
        $now = time();
        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }
        // 已锁定则不再累计（不延长窗口）
        if ((int) $user->locked_until > $now) {
            return;
        }
        // 锁定窗口已过 → 计数归零重新计
        if ((int) $user->locked_until > 0 && (int) $user->locked_until <= $now) {
            Db::table('users')->where('id', $userId)->update(['login_failed_count' => 0, 'updated_at' => $now]);
        }

        $count = Db::table('users')->where('id', $userId)->increment('login_failed_count', 1);
        $current = User::query()->find($userId);

        if ((int) $current->login_failed_count >= $threshold) {
            Db::table('users')
                ->where('id', $userId)
                ->where('locked_until', '<=', $now)
                ->update([
                    'login_failed_count' => 0,
                    'locked_until' => $now + 900,
                    'updated_at' => $now,
                ]);
            $this->auditService->audit(AuditAction::LOCKOUT, $userId, 'user', [
                'login_type' => $loginType,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * 登录成功清零失败计数。
     */
    public function resetLoginFailures(User $user): void
    {
        if ((int) $user->login_failed_count > 0 || (int) $user->locked_until > 0) {
            $user->login_failed_count = 0;
            $user->locked_until = 0;
            $user->updated_at = time();
            $user->save();
        }
    }

    // ------------------------------------------------------------------
    // 内部：会话建立 / 异地判定 / 令牌签发
    // ------------------------------------------------------------------

    /**
     * 建立完整登录态（docs/01 §4.1）：设备归并 → 异地判定 → 发双 Cookie +
     * device_key → 登录日志（主事务内同步写）→ LoginSucceeded 事件。
     */
    private function establishSession(User $user, int $loginType, bool $isNew, ?UserDevice $device = null): array
    {
        $now = time();
        $this->resetLoginFailures($user);

        // 设备归并
        if ($device === null) {
            $deviceResult = $this->deviceService->getOrCreateDevice((int) $user->id);
            $device = $deviceResult['device'];
            $cookies = [$this->deviceService->deviceCookie($deviceResult['deviceKey'])];
        } else {
            $cookies = [];
        }

        // 异地判定（D5）：随行落库，只追加不 UPDATE
        $location = $this->geoIpService->resolve(RequestContext::ip());
        $isUnusual = $this->judgeUnusual((int) $user->id, $location, (int) $device->is_trusted, $isNew);

        // 更新用户最近登录
        $user->last_login_at = $now;
        $user->last_login_ip = RequestContext::ip();
        $user->updated_at = $now;
        $user->save();

        // 登录日志：主事务内同步写（docs/01 D8）
        $this->auditService->writeLoginLog([
            'user_id' => (int) $user->id,
            'login_type' => $loginType,
            'is_success' => 1,
            'fail_reason' => 0,
            'ip' => RequestContext::ip(),
            'ip_location' => $location,
            'device_id' => (int) $device->id,
            'is_unusual' => $isUnusual,
        ]);

        // 发令牌（全量重签发，防会话固定 T3）
        $cookies = array_merge($cookies, $this->issueCookies($user, (int) $device->id));

        // 事件（事务外副作用：异地告警邮件）
        $this->dispatcher->dispatch(new LoginSucceeded(
            (int) $user->id,
            RequestContext::ip(),
            $location,
            (int) $device->id,
            $isUnusual,
            $loginType
        ));

        return [
            'user' => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'totp_enabled' => (int) $user->totp_enabled === 1,
                'kyc_level' => (int) $user->kyc_level,
            ],
            'cookies' => $cookies,
        ];
    }

    /**
     * 异地判定（docs/01 D5 / docs/04 §5.2）：基线 = 最近 30 天 / 50 条成功登录城市；
     * 同省放宽；信任设备豁免；首次登录不告警；演示账号白名单。
     */
    private function judgeUnusual(int $userId, string $location, int $isTrusted, bool $isNew): int
    {
        if ($isTrusted === 1 || $location === '' || $isNew) {
            return 0;
        }
        // 演示账号白名单（配置项，docs/01 D5）
        $demo = (string) env('UNUSUAL_WHITELIST', 'demo');
        $user = User::query()->find($userId);
        if ($user !== null && in_array((string) $user->username, explode(',', $demo), true)) {
            return 0;
        }

        $baseline = Db::table('login_logs')
            ->where('user_id', $userId)
            ->where('is_success', 1)
            ->where('created_at', '>=', time() - 30 * 86400)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('ip_location')
            ->map(fn ($v) => (string) $v)
            ->filter(fn ($v) => $v !== '')
            ->toArray();

        if ($baseline === []) {
            return 0; // 首次登录（无基线）不告警
        }

        foreach ($baseline as $city) {
            if ($city === $location || self::sameProvince($city, $location)) {
                return 0;
            }
        }
        return 1;
    }

    /**
     * 同省放宽（docs/01 D5）：「广东省深圳市」与「广东省广州市」同省不算异地。
     */
    private static function sameProvince(string $a, string $b): bool
    {
        $provinceA = preg_match('/^(.+?(省|自治区|市))/u', $a, $m) ? $m[1] : '';
        $provinceB = preg_match('/^(.+?(省|自治区|市))/u', $b, $m) ? $m[1] : '';
        return $provinceA !== '' && $provinceA === $provinceB;
    }

    /**
     * 签发双 Cookie + 新 Refresh 行（docs/04 §3.1 Cookie 属性矩阵）。
     * 返回 Cookie 对象数组，由 Controller 链式 withCookie 写入响应
     * （Hyperf Response 不可变 + 兼容测试 Client 环境）。
     *
     * @return array<\Hyperf\HttpMessage\Cookie\Cookie>
     */
    private function issueCookies(User $user, int $deviceId): array
    {
        $now = time();
        $accessTtl = (int) env('TOKEN_ACCESS_TTL', 900);
        $refreshTtl = (int) env('TOKEN_REFRESH_TTL', 2592000);
        $secure = (bool) env('COOKIE_SECURE', false);

        $access = $this->jwtService->issue((int) $user->id, (int) $user->token_version, $deviceId);

        // Refresh：random_bytes(32) hex，盐嵌入前缀，只存 sha256(salt+token)
        $rawRefresh = SecurityUtil::refreshToken();
        $salt = SecurityUtil::extractSalt($rawRefresh);
        $refreshRow = new RefreshToken();
        $refreshRow->user_id = (int) $user->id;
        $refreshRow->device_id = $deviceId;
        $refreshRow->token_hash = SecurityUtil::hash($salt, $rawRefresh);
        $refreshRow->salt = $salt;
        $refreshRow->expires_at = $now + $refreshTtl;
        $refreshRow->rotated_at = 0;
        $refreshRow->revoked_at = 0;
        $refreshRow->revoked_reason = 0;
        $refreshRow->created_at = $now;
        $refreshRow->updated_at = $now;
        $refreshRow->is_deleted = 0;
        $refreshRow->save();

        return [
            $this->makeCookie(CookieNames::ACCESS, $access, $now + $accessTtl, '/api', true),
            $this->makeCookie(CookieNames::REFRESH, $rawRefresh, $now + $refreshTtl, '/api/v1/auth/refresh', true),
            // CSRF 双提交值（docs/04 §3.2）：非 HttpOnly（前端注入 X-CSRF-Token 头）。
            // Path=/ 而非 /api：document.cookie 仅返回「当前页面路径可匹配」的 Cookie，
            // 页面位于 / 时读不到 path=/api 的 csrf_token → 写请求 419。
            // SameSite=Strict + 自定义头预检拦截提供跨站防护；服务端比对 Access 签名片段。
            $this->makeCookie('csrf_token', JwtService::csrfValue($access), $now + $accessTtl, '/', false),
            // 兼容清理：旧版（nginx 反代误改写时期）残留的 Path=/ 认证 Cookie，
            // 与新版 Path=/api 等并存时可能被部分浏览器优先发送 → 401/419。
            $this->makeCookie(CookieNames::ACCESS, '', time() - 3600, '/', true),
            $this->makeCookie(CookieNames::REFRESH, '', time() - 3600, '/', true),
            $this->makeCookie('csrf_token', '', time() - 3600, '/api', false),
        ];
    }

    /**
     * 构造 Cookie（docs/04 §3.1 属性矩阵：HttpOnly/Secure/SameSite/Path）。
     */
    private function makeCookie(string $name, string $value, int $expire, string $path, bool $httpOnly): \Hyperf\HttpMessage\Cookie\Cookie
    {
        return new \Hyperf\HttpMessage\Cookie\Cookie(
            $name,
            $value,
            $expire,
            $path,
            '',
            (bool) env('COOKIE_SECURE', false),
            $httpOnly,
            false,
            'Strict'
        );
    }

    /**
     * 轮换令牌（docs/01 §4.2）：旧行条件更新 rotated_at + 签发新对。
     */
    private function rotateToken(RefreshToken $current, UserDevice $device, User $user): array
    {
        $now = time();
        $updated = RefreshToken::query()
            ->where('id', (int) $current->id)
            ->where('rotated_at', 0)
            ->update(['rotated_at' => $now, 'updated_at' => $now]);
        if ($updated === 0) {
            // 已被并发轮换（事务内 FOR UPDATE 已串行化，理论不可达）
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }
        return $this->issueCookies($user, (int) $device->id);
    }

    /**
     * 吊销设备全部会话（重用检测 / 踢设备共用，docs/01 §4.2）。
     */
    public function revokeDeviceSession(UserDevice $device, int $reason, ?User $user = null): void
    {
        $now = time();
        Db::table('user_devices')
            ->where('id', (int) $device->id)
            ->where('revoked_at', 0)
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
        RefreshToken::query()
            ->where('device_id', (int) $device->id)
            ->where('revoked_at', 0)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => $reason,
                'updated_at' => $now,
            ]);
    }

    // ------------------------------------------------------------------
    // 内部：辅助
    // ------------------------------------------------------------------

    private function fakeHash(): string
    {
        // 预计算的 bcrypt 假哈希（cost 12），仅用于时序抹平
        static $fake = null;
        if ($fake === null) {
            $fake = password_hash('fake-password-for-timing', PASSWORD_BCRYPT, ['cost' => 12]);
        }
        return $fake;
    }

    private function assertLoginAllowed(User $user): void
    {
        if ((int) $user->status === UserStatus::DISABLED) {
            throw new BusinessException(AppErrorCode::AUTH_BAD_CREDENTIALS);
        }
        if ((int) $user->status === UserStatus::LOCKED) {
            throw new BusinessException(AppErrorCode::ACCOUNT_LOCKED, '账号已锁定，请联系客服', ['locked' => true]);
        }
        if ((int) $user->locked_until > time()) {
            throw new BusinessException(
                AppErrorCode::ACCOUNT_LOCKED,
                '账号已锁定，请 ' . ((int) $user->locked_until - time()) . ' 秒后重试',
                ['retry_after' => (int) $user->locked_until - time()]
            );
        }
    }

    private function writeFailedLoginLog(?User $user, int $loginType, int $reason): void
    {
        $this->auditService->writeLoginLog([
            'user_id' => $user?->id ?? 0,
            'login_type' => $loginType,
            'is_success' => 0,
            'fail_reason' => $reason,
            'ip' => RequestContext::ip(),
            'ip_location' => $this->geoIpService->resolve(RequestContext::ip()),
            'device_id' => 0,
            'is_unusual' => 0,
        ]);
    }

    private function createUserFromPhone(string $countryCode, string $phone): User
    {
        $now = time();
        try {
            $user = new User();
            $user->username = null;
            $user->email = null;
            $user->password_hash = '';
            $user->country_code = $countryCode;
            $user->phone = $phone;
            $user->status = UserStatus::NORMAL;
            $user->is_email_verified = 0;
            $user->is_phone_verified = 1; // L1 实名随登录完成（docs/02 §2.4）
            $user->login_failed_count = 0;
            $user->locked_until = 0;
            $user->token_version = 0;
            $user->totp_secret_encrypted = '';
            $user->totp_enabled = 0;
            $user->kyc_level = KycLevel::L1;
            $user->is_admin = 0;
            $user->last_login_at = 0;
            $user->last_login_ip = '';
            $user->created_at = $now;
            $user->updated_at = $now;
            $user->is_deleted = 0;
            $user->save();
            return $user;
        } catch (\Throwable) {
            // 并发注册唯一约束冲突 → 查回已存在账号
            $exists = User::query()
                ->where('country_code', $countryCode)
                ->where('phone', $phone)
                ->where('is_deleted', 0)
                ->first();
            if ($exists !== null) {
                return $exists;
            }
            throw new BusinessException(AppErrorCode::USER_TAKEN);
        }
    }

    private function findUserByAccount(string $account): ?User
    {
        if (str_contains($account, '+')) {
            // 手机号（含区号）：如 +8613800000000
            if (preg_match('/^(\+\d{1,4})(\d{11,15})$/', $account, $m)) {
                return User::query()
                    ->where('country_code', $m[1])
                    ->where('phone', $m[2])
                    ->where('is_deleted', 0)
                    ->first();
            }
            return null;
        }
        return User::query()->where('email', $account)->where('is_deleted', 0)->first();
    }

    private function verifyEmailCode(string $email, string $code): void
    {
        $row = Db::table('verification_codes')
            ->where('scene', VerificationScene::RESET_PASSWORD)
            ->where('email', $email)
            ->orderByDesc('id')
            ->first();
        if ($row === null || (int) $row->consumed_at > 0) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_USED);
        }
        if ((int) $row->expires_at < time()) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_EXPIRED);
        }
        if (! SecurityUtil::equals((string) $row->code_hash, SecurityUtil::hash((string) $row->salt, $code))) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_WRONG);
        }
        Db::table('verification_codes')
            ->where('id', $row->id)
            ->where('consumed_at', 0)
            ->update(['consumed_at' => time(), 'updated_at' => time()]);
    }
}
