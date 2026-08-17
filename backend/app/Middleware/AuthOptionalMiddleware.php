<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Model\User;
use App\Util\JwtService;
use App\Util\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 可选认证中间件：携带有效 Access 则注入用户上下文，否则放行（不报 401）。
 * 用于 OAuth authorize 等「未登录=登录场景 / 已登录=绑定场景」双语义接口（docs/02 §3.1）。
 */
class AuthOptionalMiddleware implements MiddlewareInterface
{
    public function __construct(protected JwtService $jwtService)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $accessToken = (string) ($cookies['access_token'] ?? '');

        if ($accessToken !== '') {
            $claims = $this->jwtService->parse($accessToken);
            if ($claims !== null) {
                $user = User::query()->find($claims['uid']);
                if ($user !== null && (int) $user->is_deleted === 0 && (int) $user->token_version === $claims['ver']) {
                    RequestContext::setUser($user->toArray());
                }
            }
        }

        return $handler->handle($request);
    }
}
