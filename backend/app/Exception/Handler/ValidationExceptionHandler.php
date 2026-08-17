<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\ValidationException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 参数校验异常处理器（400 / code 10000，带字段级 errors）。
 */
class ValidationExceptionHandler extends BusinessExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if (! $throwable instanceof ValidationException) {
            return $response;
        }

        $this->stopPropagation();

        $payload = [
            'code' => $throwable->getErrorCode(),
            'message' => $throwable->getMessage(),
            'data' => $throwable->getData(),
        ];

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
