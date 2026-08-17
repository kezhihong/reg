<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 用户主表（docs/03 §3.1，物理表 sc_users）。
 */
class User extends Model
{
    protected ?string $table = 'users';

    protected string $primaryKey = 'id';

    public bool $incrementing = true;

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'status' => 'int',
        'is_email_verified' => 'int',
        'is_phone_verified' => 'int',
        'login_failed_count' => 'int',
        'locked_until' => 'int',
        'token_version' => 'int',
        'totp_enabled' => 'int',
        'kyc_level' => 'int',
        'is_admin' => 'int',
        'last_login_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }
}
