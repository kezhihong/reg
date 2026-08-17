<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Constants\AppErrorCode;
use App\Constants\UserStatus;
use App\Exception\BusinessException;
use App\Model\User;
use App\Util\JwtService;
use App\Util\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 认证中间件（docs/01 §4.2 / docs/04 §3.3）：
 * 校验 Access Token（JWT 签名 + token_version 比对 + 用户状态），
 * 注入请求上下文 user / device。失败统一 401（code 10001，不分原因）。
 * 豁免路径（AUTH_EXCEPT_PATHS 前缀匹配）：公开接口（KYC 回调等）放行。
 */
class AuthMiddleware implements MiddlewareInterface
{
    private array $exceptPaths;

    public function __construct(protected JwtService $jwtService)
    {
        $this->exceptPaths = array_filter(array_map('trim', explode(',', (string) env('AUTH_EXCEPT_PATHS', ''))));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->exceptPaths as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $cookies = $request->getCookieParams();
        $accessToken = (string) ($cookies['access_token'] ?? '');

        $claims = $accessToken !== '' ? $this->jwtService->parse($accessToken) : null;
        if ($claims === null) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        /** @var User|null $user */
        $user = User::query()->find($claims['uid']);
        if ($user === null || $user->is_deleted === 1) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }
        if ((int) $user->status === UserStatus::DISABLED) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }
        // token_version 落后（改密/全部下线/重置后）→ 401
        if ((int) $user->token_version !== $claims['ver']) {
            throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
        }

        $device = null;
        if ($claims['did'] > 0) {
            $device = \App\Model\UserDevice::query()->find($claims['did']);
            if ($device === null || (int) $device->revoked_at > 0 || (int) $device->is_deleted === 1
                || (int) $device->user_id !== $claims['uid']) {
                throw new BusinessException(AppErrorCode::UNAUTHENTICATED);
            }
        }

        RequestContext::setUser($user->toArray());
        RequestContext::setDevice($device?->toArray());

        return $handler->handle($request);
    }
}
