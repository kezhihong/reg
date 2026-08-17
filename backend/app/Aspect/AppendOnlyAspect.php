<?php

declare(strict_types=1);

namespace App\Aspect;

use App\Exception\BusinessException;
use App\Model\AuditLog;
use App\Model\KycRecord;
use App\Model\LoginLog;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * 审计只追加应用层保障（docs/04 §7.1）：sc_audit_logs / sc_login_logs / sc_kyc_records
 * 的 Model 层禁 UPDATE/DELETE（调用即抛异常），仅允许 INSERT + SELECT。
 */
class AppendOnlyAspect extends AbstractAspect
{
    public array $classes = [
        AuditLog::class . '::update',
        AuditLog::class . '::delete',
        LoginLog::class . '::update',
        LoginLog::class . '::delete',
        KycRecord::class . '::update',
        KycRecord::class . '::delete',
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        throw new BusinessException(10008, '审计/实名记录为只追加表，禁止修改或删除');
    }
}
