<?php

declare(strict_types=1);

/**
 * 全局 helper 兼容层（Hyperf 3.1 helpers 已命名空间化）：
 * 提供全局 env() 等，使 config/*.php 与 app/ 代码保持简洁调用。
 */

if (! function_exists('env')) {
    /**
     * 读取环境变量（委托 Hyperf\Support\env）。
     */
    function env(string $key, mixed $default = null): mixed
    {
        return \Hyperf\Support\env($key, $default);
    }
}
