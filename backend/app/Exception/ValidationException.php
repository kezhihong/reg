<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 参数校验异常：字段级错误信息集合（400 / code 10000）。
 */
class ValidationException extends BusinessException
{
    public function __construct(array $errors)
    {
        $first = reset($errors);
        parent::__construct(
            \App\Constants\AppErrorCode::PARAM_INVALID,
            is_string($first) ? $first : '参数错误',
            ['errors' => $errors]
        );
    }
}
