<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * OAuth 第三方绑定表（docs/03 §3.2，物理表 sc_user_identities）。
 */
class UserIdentity extends Model
{
    protected ?string $table = 'user_identities';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'last_used_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
