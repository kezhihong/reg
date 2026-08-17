<?php

declare(strict_types=1);

namespace App\Job;

use App\Constants\NotificationChannel;
use App\Constants\NotificationStatus;
use App\Provider\EmailProviderInterface;
use App\Provider\SmsProviderInterface;
use Hyperf\AsyncQueue\Job;
use Hyperf\DbConnection\Db;
use Hyperf\Context\ApplicationContext;

/**
 * 通知发送任务（docs/01 §6 D9）：邮件/短信投递 + sc_notifications 状态机
 * （pending → sent/failed，重试 3 次指数退避 → 死信）。
 * 任务幂等：以通知记录 id 为业务键，条件更新状态防重复投递。
 */
class SendNotificationJob extends Job
{
    public function __construct(
        public readonly int $notificationId
    ) {
    }

    public function handle(): void
    {
        $row = Db::table('notifications')->find($this->notificationId);
        if ($row === null || (int) $row->status !== NotificationStatus::PENDING) {
            return; // 已处理或不存在（幂等）
        }

        $ok = $this->deliver($row);
        $now = time();

        if ($ok) {
            Db::table('notifications')
                ->where('id', $this->notificationId)
                ->where('status', NotificationStatus::PENDING)
                ->update([
                    'status' => NotificationStatus::SENT,
                    'sent_at' => $now,
                    'updated_at' => $now,
                ]);
            return;
        }

        // 失败：重试 3 次指数退避（1m/5m/30m），超过转死信（docs/01 §6）
        $retry = (int) $row->retry_count + 1;
        $nextRetry = $now + [60, 300, 1800][min($retry - 1, 2)];
        if ($retry >= 3) {
            Db::table('notifications')
                ->where('id', $this->notificationId)
                ->update([
                    'status' => NotificationStatus::DEAD,
                    'retry_count' => $retry,
                    'updated_at' => $now,
                ]);
            return;
        }
        Db::table('notifications')
            ->where('id', $this->notificationId)
            ->update([
                'status' => NotificationStatus::FAILED,
                'retry_count' => $retry,
                'next_retry_at' => $nextRetry,
                'updated_at' => $now,
            ]);
    }

    private function deliver(object $row): bool
    {
        $container = ApplicationContext::getContainer();
        try {
            if ((int) $row->channel === NotificationChannel::EMAIL) {
                $provider = $container->get(EmailProviderInterface::class);
                return $provider->send((string) $row->recipient, (string) $row->title, (string) ($row->content ?? ''));
            }
            // 短信：复用 SmsProvider（仅发送，验证码类短信在发码流程中投递）
            $provider = $container->get(SmsProviderInterface::class);
            return $provider->send('', (string) $row->recipient, (string) ($row->content ?? ''), (string) $row->scene);
        } catch (\Throwable) {
            return false;
        }
    }
}
