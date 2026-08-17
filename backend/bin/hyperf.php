<?php

declare(strict_types=1);

use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\ClassLoader;
use Psr\Container\ContainerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__) . '/');
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

// 注解扫描（AOP / 注解路由 / 注解命令，docs/01 §3.3 依赖）
ClassLoader::init();

(function () {
    /** @var ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    $application = $container->get(ApplicationInterface::class);
    $application->run();
})();
