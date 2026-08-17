<?php

declare(strict_types=1);

namespace App\Exception;

use App\Constants\AppErrorCode;
use RuntimeException;

/**
 * 业务异常统一基类（设计规范 §1.6 [必须]）：携带错误码 + 用户可读消息，
 * 禁止抛裸 \Exception。HTTP 状态码由错误码推导（docs/02 §1.2）。
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        int $code = AppErrorCode::BUSINESS_RULE,
        ?string $message = null,
        protected array $data = []
    ) {
        parent::__construct($message ?? AppErrorCode::message($code), $code);
    }

    public function getErrorCode(): int
    {
        return $this->code;
    }

    public function getHttpStatus(): int
    {
        return AppErrorCode::httpStatus($this->code);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public static function make(int $code, ?string $message = null, array $data = []): self
    {
        return new self($code, $message, $data);
    }
}
