<?php

declare(strict_types=1);

namespace App\Util;

/**
 * GeoIP 解析（docs/01 §5.3）：本地离线库 + 进程内缓存，登录热路径零远程调用。
 * dev 环境使用内置样例库（按 IP 段映射省市），生产可替换 ip2region 实现（GEOIP_DB_PATH）。
 */
class GeoIpService
{
    /** @var array<string,string> 进程内缓存：ip → 归属地 */
    private static array $cache = [];

    /**
     * 内置样例库：常见演示 IP → 省市区；未知 IP 返回空串（视为「海外/未知」）。
     */
    private const SAMPLE = [
        '127.0.0.1' => '广东省深圳市',
        '::1' => '广东省深圳市',
        '1.2.3.4' => '广东省深圳市',
        '8.8.8.8' => '北京市',
        '9.9.9.9' => '海外',
        '114.114.114.114' => '江苏省南京市',
        '202.96.128.166' => '广东省广州市',
        '101.226.103.106' => '上海市',
        '223.5.5.5' => '浙江省杭州市',
        '123.125.114.144' => '北京市',
    ];

    public function resolve(string $ip): string
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return '';
        }
        if (isset(self::$cache[$ip])) {
            return self::$cache[$ip];
        }

        $location = self::SAMPLE[$ip] ?? $this->resolveFromDb($ip);
        // 进程内缓存（TTL 数小时语义：Swoole 常驻进程生命周期内复用）
        if (count(self::$cache) < 10000) {
            self::$cache[$ip] = $location;
        }
        return $location;
    }

    private function resolveFromDb(string $ip): string
    {
        $dbPath = (string) env('GEOIP_DB_PATH', '');
        if ($dbPath !== '' && is_file($dbPath)) {
            // 预留：ip2region 离线库解析（生产接入，dev 无库文件时走样例/未知）
            // 接口约定：返回「省市区」字符串或空串
            return '';
        }
        // 未配置离线库：私有网段视为本机，其余未知
        return str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')
            ? '广东省深圳市' : '';
    }
}
