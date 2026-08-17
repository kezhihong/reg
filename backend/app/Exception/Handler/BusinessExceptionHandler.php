<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\BusinessException;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 业务异常处理器：统一 {code, message, data} 响应（docs/02 §1.2）。
 */
class BusinessExceptionHandler extends HttpExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if (! $throwable instanceof BusinessException) {
            return $response;
        }

        $this->stopPropagation();

        $payload = [
            'code' => $throwable->getErrorCode(),
            'message' => $throwable->getMessage(),
            'data' => $throwable->getData() ?? (object) [],
        ];

        $response = $response
            ->withStatus($throwable->getHttpStatus())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof BusinessException;
    }
}
