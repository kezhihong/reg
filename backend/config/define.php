<?php

declare(strict_types=1);

use Hyperf\Coroutine\Coroutine;

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__) . '/');

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

Coroutine::setHookFlags(SWOOLE_HOOK_FLAGS);

// 生产环境门禁：APP_DEBUG=true 时阻断启动（docs/05 §3.4，由部署脚本扫描，此处仅收敛显示）
if ((bool) env('APP_DEBUG', false) === false) {
    ini_set('display_errors', '0');
}
