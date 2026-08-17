<?php

declare(strict_types=1);

use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Log\LogLevel;

! defined('APP_VERSION') && define('APP_VERSION', '1.0.0');

return [
    'app_name' => env('APP_NAME', 'mem-reg'),
    'app_env' => env('APP_ENV', 'dev'),
    'app_debug' => (bool) env('APP_DEBUG', false),
    'app_version' => APP_VERSION,
    'scan_cacheable' => env('SCAN_CACHEABLE', false),
    'scan_cache_ttl' => 86400 * 30,
    StdoutLoggerInterface::class => [
        'log_level' => [
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::DEBUG,
            LogLevel::EMERGENCY,
            LogLevel::ERROR,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
        ],
    ],
];
