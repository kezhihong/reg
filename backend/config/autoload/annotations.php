<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            BASE_PATH . '/app',
        ],
        'ignore_annotations' => [
            'mixin',
        ],
        'class_map' => [],
    ],
    'cache' => [
        'enable' => env('SCAN_CACHEABLE', false),
        'stat' => true,
    ],
];
