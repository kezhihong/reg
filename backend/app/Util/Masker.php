<?php

declare(strict_types=1);

namespace App\Util;

/**
 * 脱敏工具（docs/02 §1.7）：服务端为唯一脱敏源，前端仅展示。
 */
final class Masker
{
    /**
     * 手机号：前 3 后 4（138****1234）。
     */
    public static function phone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }
        $len = strlen($phone);
        if ($len <= 7) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 7) . substr($phone, -4);
    }

    /**
     * 邮箱：首字符 + *** + @后域名（a***@example.com）。
     */
    public static function email(string $email): string
    {
        $pos = strpos($email, '@');
        if ($pos === false) {
            return '***';
        }
        $name = substr($email, 0, $pos);
        $domain = substr($email, $pos);
        $first = $name === '' ? '' : mb_substr($name, 0, 1);
        return $first . '***' . $domain;
    }

    /**
     * 证件号：前 4 后 4（1101**********1234）。
     */
    public static function idCard(string $id): string
    {
        $len = strlen($id);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($id, 0, 4) . str_repeat('*', $len - 8) . substr($id, -4);
    }

    /**
     * 真实姓名：保留姓（张*）。
     */
    public static function realName(string $name): string
    {
        if ($name === '') {
            return '';
        }
        return mb_substr($name, 0, 1) . str_repeat('*', max(1, mb_strlen($name) - 1));
    }
}
