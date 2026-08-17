<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Constants\AppErrorCode;
use App\Exception\BusinessException;
use App\Util\JwtService;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 双保险之应用层（docs/04 §3.2）：登录态下的非幂等写请求
 * 要求 X-CSRF-Token 头 = Access JWT 签名片段（无状态校验）。
 * 校验失败 → 419（code 10005）。公开接口（无 Access Cookie）豁免。
 *
 * 例外：POST /api/v1/auth/refresh 豁免——其凭证为 Refresh Cookie
 * （HttpOnly + SameSite=Strict + Path=/api/v1/auth/refresh 隔离），
 * 跨站请求无法携带/读取，本身无 CSRF 风险面；且该请求会携带
 * Access Cookie（Path=/api 匹配），若强行要求 CSRF 头会导致
 * 刷新链路在 Access/CSRF 同步过期时 419（docs/04 §3.4 轮换依赖）。
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** 豁免路径（前缀匹配）：刷新端点由 HttpOnly+SameSite+Path 隔离的 Refresh Cookie 凭证 */
    private const EXEMPT_PATHS = ['/api/v1/auth/refresh'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $cookies = $request->getCookieParams();

        if (! in_array($method, self::SAFE_METHODS, true)) {
            foreach (self::EXEMPT_PATHS as $exempt) {
                if (str_starts_with($path, $exempt)) {
                    return $handler->handle($request);
                }
            }
            $accessToken = (string) ($cookies['access_token'] ?? '');
            if ($accessToken !== '') {
                $headerToken = $request->getHeaderLine('X-CSRF-Token');
                $expected = JwtService::csrfValue($accessToken);
                if ($headerToken === '' || ! hash_equals($expected, $headerToken)) {
                    throw new BusinessException(AppErrorCode::CSRF_FAILED);
                }
            }
        }

        return $handler->handle($request);
    }
}
