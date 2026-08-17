<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 用户设备表（docs/03 §3.3，物理表 sc_user_devices）。
 */
class UserDevice extends Model
{
    protected ?string $table = 'user_devices';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'is_trusted' => 'int',
        'last_active_at' => 'int',
        'revoked_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
