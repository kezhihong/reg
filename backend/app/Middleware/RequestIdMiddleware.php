<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\RequestContext;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求链路 ID 中间件（docs/02 §1.1）：生成/透传 X-Request-Id，贯穿日志与审计。
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (! preg_match('/^[A-Za-z0-9\-]{8,64}$/', $requestId)) {
            $requestId = bin2hex(random_bytes(16));
        }
        RequestContext::setRequestId($requestId);
        RequestContext::setIp(self::clientIp($request));
        RequestContext::setUa($request->getHeaderLine('User-Agent'));

        $request = $request->withHeader('X-Request-Id', $requestId);
        Context::set(ServerRequestInterface::class, $request);

        $response = $handler->handle($request);
        return $response->withHeader('X-Request-Id', $requestId);
    }

    public static function clientIp(ServerRequestInterface $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = explode(',', $xff)[0];
            if (filter_var(trim($first), FILTER_VALIDATE_IP)) {
                return trim($first);
            }
        }
        return (string) ($request->getServerParams()['remote_addr'] ?? '127.0.0.1');
    }
}
