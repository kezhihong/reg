<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 登录日志表（docs/03 §3.8，物理表 sc_login_logs，只追加，BIGINT 主键）。
 */
class LoginLog extends Model
{
    protected ?string $table = 'login_logs';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'login_type' => 'int',
        'is_success' => 'int',
        'fail_reason' => 'int',
        'device_id' => 'int',
        'is_unusual' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
