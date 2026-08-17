<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 限流异常：命中频控时抛出（429），响应头带 X-RateLimit-* 元信息。
 */
class RateLimitException extends BusinessException
{
    public function __construct(
        int $code,
        ?string $message = null,
        protected int $limit = 0,
        protected int $remaining = 0,
        protected int $resetAt = 0,
        array $data = []
    ) {
        parent::__construct($code, $message, $data);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getRemaining(): int
    {
        return $this->remaining;
    }

    public function getResetAt(): int
    {
        return $this->resetAt;
    }
}
