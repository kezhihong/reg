<?php

declare(strict_types=1);

return [
    'handler' => [
        'http' => [
            Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler::class,
            // 限流异常必须先于 BusinessExceptionHandler（RateLimitException 是其子类）
            App\Exception\Handler\RateLimitExceptionHandler::class,
            App\Exception\Handler\BusinessExceptionHandler::class,
            App\Exception\Handler\ValidationExceptionHandler::class,
            App\Exception\Handler\AppExceptionHandler::class,
        ],
    ],
];
