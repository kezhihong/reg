<?php

declare(strict_types=1);

namespace App\Constants;

final class Env
{
    public const DEV = 'dev';
    public const TEST = 'test';
    public const PROD = 'prod';

    public static function isProd(): bool
    {
        return env('APP_ENV', self::DEV) === self::PROD;
    }

    public static function isDebug(): bool
    {
        return (bool) env('APP_DEBUG', false);
    }
}
