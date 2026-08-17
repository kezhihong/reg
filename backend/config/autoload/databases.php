<?php

declare(strict_types=1);

use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\Connectors\MySqlConnectorFactory;

return [
    'default' => [
        'driver' => 'mysql',
        'host' => env('MYSQL_HOST', '127.0.0.1'),
        'port' => (int) env('MYSQL_PORT', 3306),
        'database' => env('MYSQL_DATABASE', 'mem_reg'),
        'username' => env('MYSQL_USER', 'app'),
        'password' => env('MYSQL_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_0900_ai_ci',
        'prefix' => 'sc_',
        'pool' => [
            'min_connections' => 1,
            'max_connections' => (int) env('DB_POOL_MAX', 20),
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
        'options' => [
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
];
