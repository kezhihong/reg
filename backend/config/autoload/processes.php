<?php

declare(strict_types=1);

// 常驻进程注册（Hyperf 3.1：顶层列表；async-queue 消费端，docs/01 §6）
return [
    Hyperf\AsyncQueue\Process\ConsumerProcess::class,
];
