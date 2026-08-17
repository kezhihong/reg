<?php

declare(strict_types=1);

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\RedisDriver;
use Hyperf\AsyncQueue\Driver\RedisDriverOptions;
use Hyperf\AsyncQueue\Listener\QueueLengthListener;
use Hyperf\AsyncQueue\Listener\ReloadChannelLengthListener;
use Hyperf\AsyncQueue\Process\ConsumerProcess;
use Hyperf\AsyncQueue\Process\ConsumerProcessOptions;

return [
    'default' => [
        'driver' => RedisDriver::class,
        'channel' => 'queue',
        'timeout' => 5,
        'retry_seconds' => [1, 5, 30],
        'handle_timeout' => 30,
        'processes' => 1,
        'concurrent' => [
            'limit' => 8,
        ],
    ],
];
