<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 审计日志表（docs/03 §3.9，物理表 sc_audit_logs，只追加，BIGINT 主键）。
 * 只追加双重保障：应用层 Aspect 拦截 UPDATE/DELETE + DB 账号仅授 INSERT/SELECT。
 */
class AuditLog extends Model
{
    protected ?string $table = 'audit_logs';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'actor_type' => 'int',
        'actor_id' => 'int',
        'target_id' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
