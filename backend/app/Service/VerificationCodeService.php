<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\Env;
use App\Constants\VerificationScene;
use App\Exception\BusinessException;
use App\Model\VerificationCode;
use App\Provider\SmsProviderInterface;
use App\Util\RequestContext;
use App\Util\SecurityUtil;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * 短信/邮箱验证码服务（docs/01 D4 / docs/02 §2.3）：
 * 6 位数字随机生成，只存 sha256(salt+code)，hash_equals 校验；
 * 频控 60 秒/次 + 10 次/日；TTL 5 分钟、尝试 ≤5 次、一次性消费（条件更新）。
 */
class VerificationCodeService
{
    public function __construct(
        protected SmsProviderInterface $smsProvider,
        protected Redis $redis,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * 发送验证码（docs/02 §2.3；scene=5 更换邮箱时走邮箱通道，email 必填）。
     *
     * @return array{ttl:int, mock_code?:string}
     */
    public function send(string $countryCode, string $phone, int $scene, string $email = ''): array
    {
        $now = time();

        // 邮箱场景（scene=5）：按邮箱维度频控（60 秒/次）
        if ($scene === VerificationScene::CHANGE_EMAIL) {
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BusinessException(AppErrorCode::PARAM_INVALID, '邮箱格式不正确');
            }
            $recent = Db::table('verification_codes')
                ->where('scene', $scene)
                ->where('email', $email)
                ->orderByDesc('id')
                ->first();
            if ($recent !== null && $now - (int) $recent->created_at < 60) {
                throw new BusinessException(AppErrorCode::AUTH_CODE_TOO_FREQUENT, '验证码发送过于频繁，请稍后重试');
            }

            $code = SecurityUtil::code();
            $salt = SecurityUtil::salt();
            Db::table('verification_codes')->insert([
                'scene' => $scene,
                'country_code' => '',
                'phone' => '',
                'email' => $email,
                'code_hash' => SecurityUtil::hash($salt, $code),
                'salt' => $salt,
                'expires_at' => $now + 300,
                'max_attempts' => 5,
                'attempts' => 0,
                'consumed_at' => 0,
                'request_ip' => RequestContext::ip(),
                'created_at' => $now,
                'updated_at' => $now,
                'is_deleted' => 0,
            ]);
            // dev 环境：验证码回显（docs/05 §3.4 门禁，前端自动回填演示；生产不返回）
            $result = ['ttl' => 300, 'channel' => 'email'];
            if (! Env::isProd()) {
                $this->logger->notice('email_code_mock', ['email' => $email, 'mock_code' => $code]);
                $result['mock_code'] = $code;
            }
            return $result;
        }

        // 频控：60 秒/次 + 10 次/日（docs/04 §4.2）
        $recent = VerificationCode::query()
            ->where('scene', $scene)
            ->where('country_code', $countryCode)
            ->where('phone', $phone)
            ->orderByDesc('id')
            ->first();
        if ($recent !== null) {
            $lastAt = (int) $recent->created_at;
            if ($now - $lastAt < 60) {
                throw new BusinessException(
                    AppErrorCode::AUTH_CODE_TOO_FREQUENT,
                    '验证码发送过于频繁，请 ' . (60 - ($now - $lastAt)) . ' 秒后重试',
                    ['retry_after' => 60 - ($now - $lastAt)]
                );
            }
        }
        $dailyCount = VerificationCode::query()
            ->where('scene', $scene)
            ->where('country_code', $countryCode)
            ->where('phone', $phone)
            ->where('created_at', '>=', $now - 86400)
            ->count();
        if ($dailyCount >= 10) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_TOO_FREQUENT, '今日验证码发送次数已达上限', ['retry_after' => 86400]);
        }

        // 生成与存储：一次性凭证只存哈希 + 独立盐
        $code = SecurityUtil::code();
        $salt = SecurityUtil::salt();
        $ttl = 300;
        $codeRow = new VerificationCode();
        $codeRow->scene = $scene;
        $codeRow->country_code = $countryCode;
        $codeRow->phone = $phone;
        $codeRow->email = $email;
        $codeRow->code_hash = SecurityUtil::hash($salt, $code);
        $codeRow->salt = $salt;
        $codeRow->expires_at = $now + $ttl;
        $codeRow->max_attempts = 5;
        $codeRow->attempts = 0;
        $codeRow->consumed_at = 0;
        $codeRow->request_ip = RequestContext::ip();
        $codeRow->created_at = $now;
        $codeRow->updated_at = $now;
        $codeRow->is_deleted = 0;
        $codeRow->save();

        // 投递（mock 回显仅 dev/test 门禁；生产未配置通道 → 403）
        $result = ['ttl' => $ttl];

        $sent = $this->smsProvider->send($countryCode, $phone, $code, (string) $scene);
        if (! $sent) {
            if (Env::isProd()) {
                throw new BusinessException(AppErrorCode::FORBIDDEN, '短信通道未配置或不可用');
            }
            // dev/test 且 SMS_MOCK 关闭：mock 通道不可用时降级回显（仅非生产）
            if (! (bool) env('SMS_MOCK', false)) {
                $result['mock_code'] = $code;
            }
        } elseif (! Env::isProd() && (bool) env('SMS_MOCK', false)) {
            $result['mock_code'] = $code; // 演示环境回显（docs/02 §2.3）
        }

        return $result;
    }

    /**
     * 校验验证码（docs/02 §2.4/§8.3）：哈希校验 + 尝试次数条件递增 + 一次性消费。
     * 校验通过后自动作废（consumed_at 条件更新，原子防并发重放）。
     */
    public function verify(string $countryCode, string $phone, int $scene, string $code): void
    {
        $row = VerificationCode::query()
            ->where('scene', $scene)
            ->where('country_code', $countryCode)
            ->where('phone', $phone)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_WRONG);
        }
        $this->assertUsable($row);

        if (! SecurityUtil::equals($row->code_hash, SecurityUtil::hash($row->salt, $code))) {
            // 尝试次数原子递增（attempts < max_attempts 条件，防并发绕过，docs/04 §2.2）
            VerificationCode::query()
                ->where('id', $row->id)
                ->where('attempts', '<', $row->max_attempts)
                ->increment('attempts', 1, ['updated_at' => time()]);
            throw new BusinessException(AppErrorCode::AUTH_CODE_WRONG);
        }

        // 一次性消费：条件更新 consumed_at（0=未消费），失败即并发重放
        $consumed = VerificationCode::query()
            ->where('id', $row->id)
            ->where('consumed_at', 0)
            ->update(['consumed_at' => time(), 'updated_at' => time()]);
        if ($consumed === 0) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_USED);
        }
    }

    private function assertUsable(VerificationCode $row): void
    {
        if ((int) $row->consumed_at > 0) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_USED);
        }
        if ((int) $row->expires_at < time()) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_EXPIRED);
        }
        if ((int) $row->attempts >= (int) $row->max_attempts) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_EXPIRED, '验证码尝试次数超限，请重新获取');
        }
    }
}
