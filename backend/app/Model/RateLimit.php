<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 限流计数表（docs/03 §3.12，物理表 sc_rate_limits，仅无 Redis 降级启用）。
 */
class RateLimit extends Model
{
    protected ?string $table = 'rate_limits';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'window_start' => 'int',
        'count' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
