<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Util\RateLimiter;
use App\Util\RequestContext;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * IP 维度限流中间件（docs/04 §4.1 L1）：注册 10/分钟、登录 20/分钟、
 * 短信 30/小时、刷新 30/分钟、其余 20/分钟。429 + X-RateLimit-* 头。
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(protected RateLimiter $rateLimiter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $ip = RequestContext::ip();

        [$limit, $window] = self::policy($method, $path);
        $this->rateLimiter->hit('ip:' . $ip . ':' . $path, $limit, $window);

        return $handler->handle($request);
    }

    /**
     * 接口级 IP 限流策略（docs/02 各接口「限流」字段 + docs/04 §4.1 L1）。
     * 阈值支持环境变量覆盖（RATE_*，docs/05 §3.4；测试/CI 可调高避免互扰）。
     *
     * @return array{0:int,1:int} [limit, windowSeconds]
     */
    public static function policy(string $method, string $path): array
    {
        $key = $method . ' ' . $path;
        $envLimit = fn (string $name, int $default) => (int) env($name, $default);
        return match (true) {
            $key === 'POST /api/v1/auth/register' => [$envLimit('RATE_IP_REGISTER', 10), 60],
            $key === 'POST /api/v1/auth/sms/send' => [$envLimit('RATE_SMS_PER_HOUR', 30), 3600],
            $key === 'POST /api/v1/auth/refresh' => [$envLimit('RATE_IP_REFRESH', 30), 60],
            $key === 'POST /api/v1/auth/reset-password' => [10, 60],
            $key === 'POST /api/v1/auth/logout-all' => [10, 60],
            $key === 'POST /api/v1/auth/change-password' => [10, 60],
            str_contains($path, '/oauth/') && str_contains($path, '/bind') => [10, 60],
            str_contains($path, '/oauth/') && str_contains($path, '/unbind') => [10, 60],
            str_contains($path, '/2fa/') => [10, 60],
            str_contains($path, '/devices/') && str_contains($path, '/trust') => [10, 60],
            str_contains($path, '/kyc/l2/submit') || str_contains($path, '/kyc/l3/submit') => [10, 60],
            str_contains($path, '/kyc') => [30, 60],
            str_contains($path, '/logs/audit') => [10, 60],
            str_contains($path, '/user/me') && $method === 'PUT' => [10, 60],
            str_contains($path, '/user/me/email') => [30, 60],
            str_contains($path, '/notifications') && str_ends_with($path, '/notifications') => [20, 60],
            default => [$envLimit('RATE_IP_DEFAULT', 20), 60],
        };
    }
}
