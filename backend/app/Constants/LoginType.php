<?php

declare(strict_types=1);

namespace App\Constants;

final class LoginType
{
    public const PASSWORD = 1;
    public const SMS = 2;
    public const EMAIL = 3;
    public const GITHUB = 4;
    public const GOOGLE = 5;
}
