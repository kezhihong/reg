<?php

declare(strict_types=1);

// 事件监听器注册（Hyperf 3.1：顶层列表，docs/01 §6）
return [
    App\Listener\AuditLoggedListener::class,
    App\Listener\LoginSucceededListener::class,
    App\Listener\KycResultReceivedListener::class,
];
