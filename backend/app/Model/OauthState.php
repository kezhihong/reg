<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * OAuth state 票据表（docs/03 §3.6，物理表 sc_oauth_states）。
 */
class OauthState extends Model
{
    protected ?string $table = 'oauth_states';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'expires_at' => 'int',
        'consumed_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
