<?php

declare(strict_types=1);

namespace App\Util;

/**
 * 哈希与随机工具：一次性凭证统一 sha256(salt + value) + hash_equals（docs/04 §2.2）。
 */
final class SecurityUtil
{
    /**
     * 生成独立随机盐（8 字节 hex，CHAR(16)）。
     */
    public static function salt(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 哈希：sha256(salt + value)。
     */
    public static function hash(string $salt, string $value): string
    {
        return hash('sha256', $salt . $value);
    }

    /**
     * 常量时间比较（防时序侧信道）。
     */
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    /**
     * 6 位数字验证码（D4）。
     */
    public static function code(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * 恢复码：8 字节随机 hex（8 位字符，docs/04 §2.2）。
     */
    public static function recoveryCode(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * 32 字节随机 hex（device_key / OAuth state 原文）。
     */
    public static function randomHex32(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Refresh Token 明文：64 位 hex = 盐(16 hex) + 随机(48 hex)。
     * 盐作为 token 前缀，服务端按前缀提取盐后 O(1) 命中 uk_refresh_token_hash（docs/03 §3.4）。
     */
    public static function refreshToken(): string
    {
        return self::salt() . bin2hex(random_bytes(24));
    }

    /**
     * 从 Refresh Token 明文提取盐（前 16 hex）。
     */
    public static function extractSalt(string $token): string
    {
        return substr($token, 0, 16);
    }

    /**
     * 32 字节 raw（TOTP secret 生成等）。
     */
    public static function randomBytes32(): string
    {
        return random_bytes(32);
    }
}
