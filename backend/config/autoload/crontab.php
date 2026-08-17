<?php

declare(strict_types=1);

use Hyperf\Crontab\Crontab;

return [
    // 验证码表物理清理（超 TTL + 宽限期行，每日执行，docs/03 §5）
    (new Crontab())->setName('cleanup_verification_codes')
        ->setRule('0 3 * * *')
        ->setCallback([App\Task\CleanupTask::class, 'cleanVerificationCodes'])
        ->setMemo('物理清理过期验证码'),
    // 通知死信重试扫描（docs/01 §6 重试与死信）
    (new Crontab())->setName('retry_notifications')
        ->setRule('*/5 * * * *')
        ->setCallback([App\Task\NotificationRetryTask::class, 'retry'])
        ->setMemo('通知重试与死信处理'),
];
