<?php

declare(strict_types=1);

namespace App\Listener;

use App\Event\AuditLogged;
use App\Job\AuditBatchWriteJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

/**
 * 审计事件监听器（docs/01 §6 D8）：入 async-queue 批量落库。
 * 注意：DriverFactory 延迟从容器获取——RedisDriver 依赖事件系统，
 * 构造注入会导致循环依赖（AsyncQueueDriver → EventDispatcher → Listener → DriverFactory）。
 */
#[Listener]
class AuditLoggedListener implements ListenerInterface
{
    public function listen(): array
    {
        return [AuditLogged::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof AuditLogged) {
            return;
        }
        $container = ApplicationContext::getContainer();
        $container->get(DriverFactory::class)->get('default')->push(AuditBatchWriteJob::fromEvent($event));
    }
}
