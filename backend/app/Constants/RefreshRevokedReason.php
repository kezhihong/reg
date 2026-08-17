<?php

declare(strict_types=1);

namespace App\Constants;

final class RefreshRevokedReason
{
    public const NONE = 0;
    public const LOGOUT = 1;
    public const KICKED = 2;
    public const PASSWORD_CHANGED = 3;
    public const REUSE_DETECTED = 4;
    public const EXPIRED = 5;
    public const GLOBAL_REVOKE = 6;
}
