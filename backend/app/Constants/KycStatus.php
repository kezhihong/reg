<?php

declare(strict_types=1);

namespace App\Constants;

final class KycStatus
{
    public const SUBMITTING = 1;
    public const REVIEWING = 2;
    public const APPROVED = 3;
    public const REJECTED = 4;
    public const EXPIRED = 5;
}
