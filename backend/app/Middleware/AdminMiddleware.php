<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Constants\AppErrorCode;
use App\Exception\BusinessException;
use App\Model\User;
use App\Util\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管理员中间件（docs/02 §7.2）：GET /logs/audit 要求 is_admin=1，否则 403（15101）。
 */
class AdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = RequestContext::user();
        if ($user === null || (int) ($user['is_admin'] ?? 0) !== 1) {
            throw new BusinessException(AppErrorCode::LOG_AUDIT_FORBIDDEN);
        }
        return $handler->handle($request);
    }
}
