<?php

declare(strict_types=1);

namespace App\Util;

/**
 * UA 解析：提取设备名（docs/03 §3.3 device_name 示例：Chrome 126 / Windows 11）。
 * 仅做浏览器/系统名提取，失败返回 '未知设备'。
 */
final class UserAgentParser
{
    public static function parse(string $ua): string
    {
        $browser = self::browser($ua);
        $os = self::os($ua);
        if ($browser === '' && $os === '') {
            return '未知设备';
        }
        return trim($browser . ' / ' . $os, ' /');
    }

    private static function browser(string $ua): string
    {
        if (stripos($ua, 'Edg/') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'Chrome/') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Firefox/') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari/') !== false) {
            return 'Safari';
        }
        if (stripos($ua, 'curl/') !== false) {
            return 'curl';
        }
        return '';
    }

    private static function os(string $ua): string
    {
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            return 'iOS';
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }
        return '';
    }
}
