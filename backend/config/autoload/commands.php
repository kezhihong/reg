<?php

declare(strict_types=1);

// CLI 命令注册（Hyperf 3.1：顶层列表；注解 #[Command] 也会自动收集）
return [
    App\Command\MigrateCommand::class,
    App\Command\SeedCommand::class,
];
