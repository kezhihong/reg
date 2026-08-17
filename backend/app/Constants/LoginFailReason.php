<?php

declare(strict_types=1);

namespace App\Constants;

final class LoginFailReason
{
    public const NONE = 0;
    public const PASSWORD_WRONG = 1;
    public const ACCOUNT_NOT_FOUND = 2;
    public const LOCKED = 3;
    public const DISABLED = 4;
    public const CODE_WRONG = 5;
    public const TOTP_FAILED = 6;
    public const RATE_LIMITED = 7;
}
