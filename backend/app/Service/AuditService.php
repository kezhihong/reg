<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\ActorType;
use App\Event\AuditLogged;
use App\Util\RequestContext;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * 日志与审计服务（docs/01 §3.2 / D8）：
 * - sc_login_logs：登录主事务内同步写（只追加，随行落异地判定）
 * - sc_audit_logs：异步队列批量插入（只追加双重保障：Aspect 拦截 + DB 权限）
 */
class AuditService
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * 审计事件入队（docs/04 §7.1 action 常量）。
     */
    public function audit(
        string $action,
        int $targetId = 0,
        string $targetType = '',
        array $detail = [],
        ?int $actorId = null,
        int $actorType = ActorType::USER
    ): void {
        $this->dispatcher->dispatch(new AuditLogged(
            $action,
            $actorType,
            $actorId ?? RequestContext::userId(),
            $targetType,
            $targetId,
            RequestContext::requestId(),
            RequestContext::ip(),
            RequestContext::ua(),
            $detail
        ));
    }

    /**
     * 登录日志同步写（docs/01 D8：与失败计数/锁定同事务保证一致）。
     */
    public function writeLoginLog(array $data): void
    {
        Db::table('login_logs')->insert([
            'user_id' => $data['user_id'] ?? 0,
            'login_type' => $data['login_type'] ?? 0,
            'oauth_provider' => $data['oauth_provider'] ?? '',
            'is_success' => $data['is_success'] ?? 1,
            'fail_reason' => $data['fail_reason'] ?? 0,
            'country_code' => $data['country_code'] ?? '',
            'phone' => $data['phone'] ?? '',
            'ip' => $data['ip'] ?? RequestContext::ip(),
            'ip_location' => $data['ip_location'] ?? '',
            'user_agent' => $data['user_agent'] ?? RequestContext::ua(),
            'device_id' => $data['device_id'] ?? 0,
            'is_unusual' => $data['is_unusual'] ?? 0,
            'request_id' => $data['request_id'] ?? RequestContext::requestId(),
            'created_at' => time(),
            'updated_at' => time(),
            'is_deleted' => 0,
        ]);
    }
}
