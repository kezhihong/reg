<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 通知发送记录表（docs/03 §3.10，物理表 sc_notifications，BIGINT 主键）。
 */
class Notification extends Model
{
    protected ?string $table = 'notifications';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'channel' => 'int',
        'scene' => 'int',
        'status' => 'int',
        'retry_count' => 'int',
        'next_retry_at' => 'int',
        'sent_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
