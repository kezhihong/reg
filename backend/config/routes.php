<?php

declare(strict_types=1);

// 注解路由为唯一路由来源（docs/01 §3.3：Controller 注解注册），
// 本文件仅保留健康检查等非注解路由。
use Hyperf\HttpServer\Router\Router;

Router::get('/health', function () {
    return ['status' => 'ok', 'time' => time()];
});
