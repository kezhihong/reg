<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\RateLimitException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 限流异常处理器：429 + X-RateLimit-Limit / Remaining / Reset 响应头（docs/02 §1.5）。
 */
class RateLimitExceptionHandler extends BusinessExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if (! $throwable instanceof RateLimitException) {
            return $response;
        }

        $this->stopPropagation();

        $payload = [
            'code' => $throwable->getErrorCode(),
            'message' => $throwable->getMessage(),
            'data' => (object) [],
        ];

        $response = $response
            ->withStatus(429)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-RateLimit-Limit', (string) $throwable->getLimit())
            ->withHeader('X-RateLimit-Remaining', (string) $throwable->getRemaining())
            ->withHeader('X-RateLimit-Reset', (string) $throwable->getResetAt())
            ->withBody(new SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof RateLimitException;
    }
}
