<?php

declare(strict_types=1);

namespace App\Event;

use Hyperf\AsyncQueue\Event\Event;

/**
 * 登录成功领域事件（docs/01 §6 / D9）：事务提交后发布，
 * 消费端处理异地告警邮件等副作用，发送失败绝不影响登录结果。
 */
class LoginSucceeded extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $ip,
        public readonly string $location,
        public readonly int $deviceId,
        public readonly int $isUnusual,
        public readonly int $loginType
    ) {
    }
}
