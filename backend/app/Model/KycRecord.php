<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * KYC 实名记录表（docs/03 §3.7，物理表 sc_kyc_records，只追加）。
 */
class KycRecord extends Model
{
    protected ?string $table = 'kyc_records';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'level' => 'int',
        'status' => 'int',
        'reviewed_by' => 'int',
        'reviewed_at' => 'int',
        'created_at' => 'int',
        'updated_at' => 'int',
        'is_deleted' => 'int',
    ];
}
