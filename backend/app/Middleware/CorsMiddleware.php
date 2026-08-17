<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS 中间件（docs/01 §3.3）：SPA 同源部署时仅允许同源，预留跨域配置项。
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $response = $handler->handle($request);

        $allowed = (string) env('CORS_ALLOWED_ORIGIN', '');
        if ($origin !== '' && $allowed !== '' && $origin === $allowed) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token, X-Request-Id, X-Idempotency-Key')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $response->withStatus(204);
        }
        return $response;
    }
}
