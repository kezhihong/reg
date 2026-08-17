<?php

declare(strict_types=1);

return [
    'http' => [
        // 中间件链（docs/01 §3.3 顺序）：RequestId → RateLimit → Cors → Csrf → Auth（局部注解）
        App\Middleware\RequestIdMiddleware::class,
        App\Middleware\RateLimitMiddleware::class,
        App\Middleware\CorsMiddleware::class,
        App\Middleware\CsrfMiddleware::class,
    ],
];
