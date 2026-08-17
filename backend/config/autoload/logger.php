<?php

declare(strict_types=1);

use Monolog\Level;

$logLevel = match (strtolower((string) env('LOG_LEVEL', 'debug'))) {
    'debug' => Level::Debug->value,
    'info' => Level::Info->value,
    'notice' => Level::Notice->value,
    'warning' => Level::Warning->value,
    'error' => Level::Error->value,
    default => Level::Info->value,
};

return [
    'default' => [
        'handler' => [
            'class' => \Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'stream' => 'php://stdout',
                'level' => $logLevel,
            ],
        ],
        'formatter' => [
            'class' => \Monolog\Formatter\JsonFormatter::class,
            'constructor' => [
                'batchMode' => \Monolog\Formatter\JsonFormatter::BATCH_MODE_NEWLINES,
                'appendNewline' => true,
            ],
        ],
        'processors' => [
            // Hyperf 3.1：数组格式才会经容器实例化（字符串类名会被 Monolog 当函数调用）
            ['class' => \App\Util\LogContextProcessor::class],
        ],
        'timezone' => 'Asia/Shanghai',
    ],
];
