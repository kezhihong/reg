<?php

declare(strict_types=1);

namespace App\Event;

use Hyperf\AsyncQueue\Event\Event;

/**
 * KYC 结果事件（docs/01 §6）：回调落结果后发布，驱动通知与审计。
 */
class KycResultReceived extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $recordId,
        public readonly int $status
    ) {
    }
}
