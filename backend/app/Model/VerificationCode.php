<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 一次性验证码表（docs/03 §3.5，物理表 sc_verification_codes，BIGINT 主键）。
 */
class VerificationCode extends Model
{
    protected ?string $table = 'verification_codes';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'scene' => 'int',
        'max_attempts' => 'int',
        'attempts' => 'int',
        'expires_at' => 'int',
        'consumed_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
