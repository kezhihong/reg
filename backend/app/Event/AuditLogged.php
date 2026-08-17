<?php

declare(strict_types=1);

namespace App\Event;

use Hyperf\AsyncQueue\Event\Event;

/**
 * 审计事件（docs/01 §6）：入队后批量插入 sc_audit_logs（数百条/批，消费端只 INSERT）。
 */
class AuditLogged extends Event
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
}
