<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 2FA 恢复码表（docs/03 §3.11，物理表 sc_totp_recovery_codes）。
 */
class TotpRecoveryCode extends Model
{
    protected ?string $table = 'totp_recovery_codes';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'used_at' => 'int',
        'expires_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
