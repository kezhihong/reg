<?php

declare(strict_types=1);

namespace App\Job;

use App\Constants\KycStatus;
use App\Provider\KycProviderInterface;
use Hyperf\AsyncQueue\Job;
use Hyperf\DbConnection\Db;
use Hyperf\Context\ApplicationContext;

/**
 * KYC 异步校验任务（docs/01 §6 D6）：调第三方 + 回写 sc_kyc_records + 事件。
 * 任务幂等：以 provider_request_id 唯一约束兜底（重复执行不重复生效）。
 */
class KycVerifyJob extends Job
{
    public function __construct(
        public readonly int $recordId,
        public readonly int $level
    ) {
    }

    public function handle(): void
    {
        $record = Db::table('kyc_records')->find($this->recordId);
        if ($record === null || (int) $record->status !== KycStatus::SUBMITTING) {
            return; // 幂等：仅提交中可流转
        }

        $container = ApplicationContext::getContainer();
        $provider = $container->get(KycProviderInterface::class);
        $now = time();

        try {
            if ($this->level === 2) {
                $result = $provider->verifyL2(
                    (string) $record->real_name,
                    (string) $record->id_card_number,
                    (string) $record->country_code,
                    (string) $record->phone
                );
            } else {
                // L3：从提交请求留痕中取活体会话凭证（docs/02 §6.5）
                $requestDetail = json_decode((string) ($record->request_detail ?? ''), true) ?: [];
                $session = (string) ($requestDetail['provider_session'] ?? '');
                $result = $provider->verifyL3($session);
            }

            $success = (bool) ($result['success'] ?? false);
            Db::table('kyc_records')
                ->where('id', $this->recordId)
                ->where('status', KycStatus::SUBMITTING)
                ->update([
                    'status' => $success ? KycStatus::REVIEWING : KycStatus::REJECTED,
                    'result_detail' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'fail_reason' => $success ? '' : (string) ($result['message'] ?? '校验失败'),
                    'updated_at' => $now,
                ]);

            if ($success) {
                // 模拟第三方回调（docs/02 §10.4：异步校验完成后经回调/轮询双通道落结果）
                $this->simulateCallback((int) $record->user_id, $this->recordId, $this->level);
            }
        } catch (\Throwable $e) {
            Db::table('kyc_records')
                ->where('id', $this->recordId)
                ->where('status', KycStatus::SUBMITTING)
                ->update([
                    'status' => KycStatus::REJECTED,
                    'fail_reason' => '第三方校验异常',
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * dev 环境无真实第三方回调：任务内直接落结果（等价于第三方回调到达）。
     */
    private function simulateCallback(int $userId, int $recordId, int $level): void
    {
        $now = time();
        Db::table('kyc_records')
            ->where('id', $recordId)
            ->where('status', KycStatus::REVIEWING)
            ->update([
                'status' => KycStatus::APPROVED,
                'callback_raw' => '{"mock":true,"result":"approved"}',
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

        // 提升用户等级（单调递增，不降级）
        Db::table('users')
            ->where('id', $userId)
            ->where('kyc_level', '<', $level)
            ->update([
                'kyc_level' => $level,
                'updated_at' => $now,
            ]);

        // 发布 KYC 结果事件（通知/审计）
        \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\Psr\EventDispatcher\EventDispatcherInterface::class)
            ->dispatch(new \App\Event\KycResultReceived($userId, $recordId, KycStatus::APPROVED));
    }
}
