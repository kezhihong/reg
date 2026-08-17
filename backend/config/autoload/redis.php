<?php

declare(strict_types=1);

return [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'auth' => env('REDIS_PASSWORD', null),
        'port' => (int) env('REDIS_PORT', 6379),
        'db' => (int) env('REDIS_DB', 0),
        'pool' => [
            'min_connections' => 1,
            'max_connections' => (int) env('REDIS_POOL_MAX', 20),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        'options' => [
            \Redis::OPT_SERIALIZER => \Redis::SERIALIZER_NONE,
            \Redis::OPT_PREFIX => 'mem_reg:',
        ],
    ],
];
