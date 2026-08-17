<?php

declare(strict_types=1);

namespace App\Listener;

use App\Constants\AuditAction;
use App\Event\KycResultReceived;
use Hyperf\Context\ApplicationContext;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * KYC 结果监听器（docs/01 §6）：结果落库后补发审计事件。
 * EventDispatcher 延迟获取（防循环依赖：ListenerProvider 解析期间不可再取 Dispatcher）。
 */
#[Listener]
class KycResultReceivedListener implements ListenerInterface
{
    public function listen(): array
    {
        return [KycResultReceived::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof KycResultReceived) {
            return;
        }
        $container = ApplicationContext::getContainer();
        $container->get(EventDispatcherInterface::class)->dispatch(new AuditLogged(
            AuditAction::KYC_RESULT_RECEIVED,
            \App\Constants\ActorType::USER,
            $event->userId,
            'kyc_record',
            $event->recordId,
            \App\Util\RequestContext::requestId(),
            \App\Util\RequestContext::ip(),
            \App\Util\RequestContext::ua(),
            ['status' => $event->status]
        ));
    }
}
