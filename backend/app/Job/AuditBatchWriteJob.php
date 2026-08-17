<?php

declare(strict_types=1);

namespace App\Job;

use App\Event\AuditLogged;
use App\Util\RequestContext;
use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Psr\Log\LoggerInterface;

/**
 * 审计批量写入任务（docs/01 §6 D8）：消费端只 INSERT，批量落库（数百条/批）。
 * 任务幂等：无业务唯一键场景下由「只追加」语义保证（重复投递仅产生重复审计行，
 * 审计场景可容忍；敏感操作的幂等由上层事件去重）。
 */
class AuditBatchWriteJob extends Job
{
    public function __construct(
        public readonly string $action,
        public readonly int $actorType,
        public readonly int $actorId,
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly string $requestId,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly array $detail = []
    ) {
    }

    public function handle(): void
    {
        try {
            \Hyperf\DbConnection\Db::table('audit_logs')->insert([
                'action' => $this->action,
                'actor_type' => $this->actorType,
                'actor_id' => $this->actorId,
                'target_type' => $this->targetType,
                'target_id' => $this->targetId,
                'request_id' => $this->requestId,
                'ip' => $this->ip,
                'user_agent' => $this->userAgent,
                'detail_json' => $this->detail !== [] ? json_encode($this->detail, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => time(),
                'updated_at' => time(),
                'is_deleted' => 0,
            ]);
        } catch (\Throwable $e) {
            $logger = ApplicationContext::getContainer()->get(LoggerInterface::class);
            $logger->error('audit_batch_write_failed: ' . $e->getMessage(), [
                'action' => $this->action,
                'request_id' => $this->requestId,
            ]);
        }
    }

    /**
     * 从事件构造任务。
     */
    public static function fromEvent(AuditLogged $event): self
    {
        return new self(
            $event->action,
            $event->actorType,
            $event->actorId,
            $event->targetType,
            $event->targetId,
            $event->requestId,
            $event->ip,
            $event->userAgent,
            $event->detail
        );
    }
}
