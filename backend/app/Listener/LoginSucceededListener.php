<?php

declare(strict_types=1);

namespace App\Listener;

use App\Constants\NotificationChannel;
use App\Constants\NotificationScene;
use App\Event\LoginSucceeded;
use App\Job\SendNotificationJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

/**
 * 登录成功监听器（docs/01 §6 D9）：unusual=1 → 构造异地告警邮件（异步队列）。
 * 发送失败绝不影响登录结果（事务外、队列解耦）。DriverFactory 延迟获取（防循环依赖）。
 */
#[Listener]
class LoginSucceededListener implements ListenerInterface
{
    public function listen(): array
    {
        return [LoginSucceeded::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof LoginSucceeded) {
            return;
        }
        if ($event->isUnusual !== 1) {
            return;
        }

        // 降噪（docs/04 §5.2）：同用户最近 24 小时同城市已告警则不重复
        $recent = Db::table('notifications')
            ->where('user_id', $event->userId)
            ->where('scene', NotificationScene::UNUSUAL_LOGIN)
            ->where('created_at', '>=', time() - 86400)
            ->where('status', '<>', \App\Constants\NotificationStatus::DEAD)
            ->exists();
        if ($recent) {
            return;
        }

        $user = Db::table('users')->find($event->userId);
        if ($user === null || $user->email === '') {
            return; // 无邮箱则不告警
        }

        $now = time();
        $id = Db::table('notifications')->insertGetId([
            'user_id' => $event->userId,
            'channel' => NotificationChannel::EMAIL,
            'scene' => NotificationScene::UNUSUAL_LOGIN,
            'recipient' => (string) $user->email,
            'title' => '异地登录安全提醒',
            'content' => sprintf(
                '您的账号于 %s 在 %s（IP: %s）登录。如非本人操作，请立即修改密码并检查设备列表。',
                date('Y-m-d H:i:s', $now),
                $event->location !== '' ? $event->location : '未知地点',
                $event->ip
            ),
            'status' => \App\Constants\NotificationStatus::PENDING,
            'provider' => 'mock',
            'retry_count' => 0,
            'next_retry_at' => 0,
            'sent_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'is_deleted' => 0,
        ]);

        $container = ApplicationContext::getContainer();
        $container->get(DriverFactory::class)->get('default')->push(new SendNotificationJob((int) $id));
    }
}
