<?php

declare(strict_types=1);

namespace App\Task;

use App\Constants\NotificationStatus;
use App\Job\SendNotificationJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\DbConnection\Db;
use Psr\Log\LoggerInterface;

/**
 * 通知重试任务（docs/01 §6 / docs/03 §5）：扫描到期待发时间的失败通知重新入队。
 */
class NotificationRetryTask
{
    public function __construct(
        protected DriverFactory $driverFactory,
        protected LoggerInterface $logger
    ) {
    }

    public function retry(): void
    {
        $rows = Db::table('notifications')
            ->where('status', NotificationStatus::FAILED)
            ->where('next_retry_at', '>', 0)
            ->where('next_retry_at', '<=', time())
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            Db::table('notifications')
                ->where('id', $row->id)
                ->where('status', NotificationStatus::FAILED)
                ->update(['status' => NotificationStatus::PENDING, 'updated_at' => time()]);
            $this->driverFactory->get('default')->push(new SendNotificationJob((int) $row->id));
        }
    }
}
