<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\AuditAction;
use App\Constants\KycLevel;
use App\Constants\LoginType;
use App\Exception\BusinessException;
use App\Model\TotpRecoveryCode;
use App\Model\User;
use App\Util\CryptoService;
use App\Util\SecurityUtil;
use App\Util\TicketService;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA（TOTP）服务（docs/01 D7 / docs/02 §4）：
 * secret AES-256-GCM 加密落库；±1 时间步容差 + 同 time_step 防重放；
 * 10 个恢复码逐码哈希 + 独立盐，一次性消费，2FA 关闭全部作废。
 */
class TotpService
{
    private const RECOVERY_CACHE_TTL = 2592000; // 30 天（明文仅存 Redis 密文，DB 只存哈希）

    public function __construct(
        protected CryptoService $cryptoService,
        protected TicketService $ticketService,
        protected Redis $redis,
        protected AuthService $authService,
        protected AuditService $auditService
    ) {
    }

    /**
     * 4.1 生成 TOTP secret（不落库，仅本次响应返回明文）。
     *
     * @return array{secret:string,otpauth_uri:string,qr_data:string}
     */
    public function start(User $user): array
    {
        if ((int) $user->totp_enabled === 1) {
            throw new BusinessException(AppErrorCode::TOTP_ALREADY_ENABLED);
        }
        $secret = (new Google2FA())->generateSecretKey(32);
        $account = $user->username !== '' ? (string) $user->username : 'user_' . $user->id;
        $issuer = (string) env('APP_NAME', 'mem-reg');
        $otpauth = (new Google2FA())->getQRCodeUrl($issuer, $account, $secret);

        return [
            'secret' => $secret,
            'otpauth_uri' => $otpauth,
            'qr_data' => $otpauth,
        ];
    }

    /**
     * 4.2 校验并启用：校验通过才加密持久化 + 生成 10 个恢复码。
     *
     * @return array{recovery_codes:string[]}
     */
    public function enableVerify(User $user, string $secret, string $code): array
    {
        if ((int) $user->totp_enabled === 1) {
            throw new BusinessException(AppErrorCode::TOTP_ALREADY_ENABLED);
        }
        if (! $this->verifyTotpCode($secret, $code, (int) $user->id)) {
            throw new BusinessException(AppErrorCode::TOTP_CODE_WRONG);
        }

        $now = time();
        $user->totp_secret_encrypted = $this->cryptoService->encrypt($secret);
        $user->totp_enabled = 1;
        $user->updated_at = $now;
        $user->save();

        // 10 个恢复码：逐码哈希 + 独立盐落库，明文加密存 Redis 供单查（docs/02 §4.6）
        $codes = [];
        $plainCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $plain = SecurityUtil::recoveryCode();
            $plainCodes[] = $plain;
            $codes[] = [
                'user_id' => (int) $user->id,
                'code_hash' => SecurityUtil::hash(SecurityUtil::salt(), $plain),
                'salt' => SecurityUtil::salt(),
                'used_at' => 0,
                'expires_at' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
            ];
        }
        Db::table('totp_recovery_codes')->insert($codes);
        $this->cachePlainCodes((int) $user->id, $plainCodes);

        $this->auditService->audit(AuditAction::TOTP_ENABLED, (int) $user->id, 'user');

        return ['recovery_codes' => $plainCodes];
    }

    /**
     * 4.3 关闭 2FA：验证动态码或恢复码 → 清除 secret → 恢复码全部作废 → 全局吊销。
     */
    public function disable(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ((int) $user->totp_enabled !== 1) {
            throw new BusinessException(AppErrorCode::TOTP_NOT_ENABLED);
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $this->consumeRecoveryCode((int) $user->id, $recoveryCode);
        } elseif ($code !== null && $code !== '') {
            $secret = $this->cryptoService->decrypt((string) $user->totp_secret_encrypted);
            if (! $this->verifyTotpCode($secret, $code, (int) $user->id)) {
                throw new BusinessException(AppErrorCode::TOTP_CODE_WRONG);
            }
        } else {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '动态码与恢复码至少提供其一');
        }

        $now = time();
        $user->totp_enabled = 0;
        $user->totp_secret_encrypted = '';
        $user->updated_at = $now;
        $user->save();

        // 恢复码全部作废（条件更新 used_at=0 的行，docs/03 §3.11）
        Db::table('totp_recovery_codes')
            ->where('user_id', $user->id)
            ->where('used_at', 0)
            ->update(['expires_at' => $now, 'updated_at' => $now]);
        $this->redis->del('totp:recovery:' . $user->id);

        // 触发全局吊销（docs/02 §4.3：所有设备重新登录）
        $this->authService->bumpTokenVersion((int) $user->id);
        $this->auditService->audit(AuditAction::TOTP_DISABLED, (int) $user->id, 'user');
    }

    /**
     * 4.4 登录二次验证（docs/02 §4.4）：验票据 + 验动态码 → 建立完整登录态。
     */
    public function loginVerify(string $ticket, string $code): array
    {
        $claims = $this->ticketService->parse($ticket);
        if ($claims === null) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED, '登录票据无效或已过期');
        }
        $user = User::query()->find($claims['uid']);
        if ($user === null || (int) $user->totp_enabled !== 1) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }
        $secret = $this->cryptoService->decrypt((string) $user->totp_secret_encrypted);

        if (! $this->verifyTotpCode($secret, $code, (int) $user->id)) {
            $this->authService->recordLoginFailure((int) $user->id, 5, LoginType::PASSWORD);
            $this->auditService->audit(AuditAction::LOGIN_FAILED, (int) $user->id, 'user', [
                'login_type' => LoginType::PASSWORD,
                'fail_reason' => '2FA 失败',
            ]);
            throw new BusinessException(AppErrorCode::TOTP_CODE_WRONG);
        }

        return $this->authService->completeTotpLogin((int) $user->id, (int) $claims['did']);
    }

    /**
     * 4.5 登录时恢复码验证。
     */
    public function loginRecoveryVerify(string $ticket, string $recoveryCode): array
    {
        $claims = $this->ticketService->parse($ticket);
        if ($claims === null) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED, '登录票据无效或已过期');
        }
        $user = User::query()->find($claims['uid']);
        if ($user === null || (int) $user->totp_enabled !== 1) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        try {
            $this->consumeRecoveryCode((int) $user->id, $recoveryCode);
        } catch (BusinessException $e) {
            $this->authService->recordLoginFailure((int) $user->id, 5, LoginType::PASSWORD);
            throw $e;
        }

        $this->auditService->audit(AuditAction::RECOVERY_CODE_USED, (int) $user->id, 'user');

        return $this->authService->completeTotpLogin((int) $user->id, (int) $claims['did']);
    }

    /**
     * 4.6 恢复码查询：启用后首次返回完整 10 个，此后仅统计；按位单查。
     */
    public function recoveryCodes(User $user): array
    {
        if ((int) $user->totp_enabled !== 1) {
            throw new BusinessException(AppErrorCode::TOTP_NOT_ENABLED);
        }
        $total = Db::table('totp_recovery_codes')->where('user_id', $user->id)->count();
        $remaining = Db::table('totp_recovery_codes')
            ->where('user_id', $user->id)
            ->where('used_at', 0)
            ->count();

        $plain = $this->readPlainCodes((int) $user->id);
        $shown = (int) $this->redis->get('totp:recovery_shown:' . $user->id) === 1;

        if ($plain !== [] && ! $shown) {
            $this->redis->set('totp:recovery_shown:' . $user->id, 1);
            return ['codes' => $plain, 'total' => $total, 'remaining' => $remaining];
        }
        return ['total' => $total, 'remaining' => $remaining];
    }

    /**
     * 4.6 按位单查（限频 60 秒/次由路由/Controller 层执行）。
     */
    public function singleRecoveryCode(User $user, int $index): string
    {
        if ((int) $user->totp_enabled !== 1) {
            throw new BusinessException(AppErrorCode::TOTP_NOT_ENABLED);
        }
        $plain = $this->readPlainCodes((int) $user->id);
        if (! isset($plain[$index])) {
            throw new BusinessException(AppErrorCode::TOTP_RECOVERY_INVALID);
        }
        return $plain[$index];
    }

    /**
     * TOTP 校验：±1 时间步容差 + 同一 time_step 防重放（Redis SETNX，docs/01 D7）。
     */
    private function verifyTotpCode(string $secret, string $code, int $userId): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $google2fa = new Google2FA();
        if (! $google2fa->verifyKey($secret, $code, 1)) {
            return false;
        }
        // 防重放：当前 time_step 已用过 → 拒绝（12102）
        $step = intdiv(time(), 30);
        $lock = $this->redis->setnx('totp:used:' . $userId . ':' . $step, '1');
        if ($lock === false) {
            throw new BusinessException(AppErrorCode::TOTP_CODE_REPLAY);
        }
        $this->redis->expire('totp:used:' . $userId . ':' . $step, 90);
        return true;
    }

    /**
     * 消费恢复码（docs/02 §4.5）：哈希比对 + 一次性条件更新。
     */
    private function consumeRecoveryCode(int $userId, string $recoveryCode): void
    {
        $rows = Db::table('totp_recovery_codes')
            ->where('user_id', $userId)
            ->where('used_at', 0)
            ->get();
        foreach ($rows as $row) {
            if (SecurityUtil::equals((string) $row->code_hash, SecurityUtil::hash((string) $row->salt, $recoveryCode))) {
                $updated = Db::table('totp_recovery_codes')
                    ->where('id', $row->id)
                    ->where('used_at', 0)
                    ->update(['used_at' => time(), 'updated_at' => time()]);
                if ($updated === 0) {
                    throw new BusinessException(AppErrorCode::TOTP_RECOVERY_INVALID, '恢复码已使用');
                }
                return;
            }
        }
        throw new BusinessException(AppErrorCode::TOTP_RECOVERY_INVALID);
    }

    /**
     * 恢复码明文仅存 Redis（AES-256-GCM 密文），DB 只存哈希（docs/02 §4.6 单查能力）。
     */
    private function cachePlainCodes(int $userId, array $codes): void
    {
        $payload = $this->cryptoService->encrypt(json_encode($codes, JSON_UNESCAPED_UNICODE));
        $this->redis->set('totp:recovery:' . $userId, $payload, self::RECOVERY_CACHE_TTL);
    }

    /**
     * @return string[] 明文恢复码（Redis 密文解密），不可用时返回 []
     */
    private function readPlainCodes(int $userId): array
    {
        $payload = (string) $this->redis->get('totp:recovery:' . $userId);
        if ($payload === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->cryptoService->decrypt($payload), true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
