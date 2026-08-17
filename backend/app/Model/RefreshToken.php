<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * Refresh Token 表（docs/03 §3.4，物理表 sc_refresh_tokens，BIGINT 主键）。
 */
class RefreshToken extends Model
{
    protected ?string $table = 'refresh_tokens';

    protected string $primaryKey = 'id';

    public bool $incrementing = true;

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'device_id' => 'int',
        'expires_at' => 'int',
        'rotated_at' => 'int',
        'revoked_at' => 'int',
        'revoked_reason' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
