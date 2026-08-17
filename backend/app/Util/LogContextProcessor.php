<?php

declare(strict_types=1);

namespace App\Util;

use Hyperf\Context\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 日志上下文处理器（docs/05 §4.1）：为每条日志附加
 * request_id / user_id / device_id / action / ip / ua 等固定字段，JSON 输出与 K8s 同构。
 */
class LogContextProcessor implements ProcessorInterface
{
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        $extra = [
            'request_id' => RequestContext::requestId(),
            'user_id' => RequestContext::userId() ?: null,
            'device_id' => RequestContext::deviceId() ?: null,
            'ip' => RequestContext::ip() ?: null,
        ];

        if (is_array($record)) {
            $record['extra'] = array_merge($record['extra'] ?? [], array_filter($extra));
            return $record;
        }

        foreach (array_filter($extra) as $k => $v) {
            $record->extra[$k] = $v;
        }
        return $record;
    }
}
