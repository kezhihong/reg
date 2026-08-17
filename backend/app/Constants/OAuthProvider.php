<?php

declare(strict_types=1);

namespace App\Constants;

final class OAuthProvider
{
    public const GITHUB = 'github';
    public const GOOGLE = 'google';

    public const ALL = [self::GITHUB, self::GOOGLE];
}
