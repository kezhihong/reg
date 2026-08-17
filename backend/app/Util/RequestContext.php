<?php

declare(strict_types=1);

namespace App\Util;

use Hyperf\Context\Context;

/**
 * 请求上下文（docs/01 §3.3）：协程上下文持有 request_id / user / device / ip / ua，
 * 供日志与审计统一取用。
 */
final class RequestContext
{
    private const REQUEST_ID = 'ctx.request_id';
    private const USER = 'ctx.user';
    private const DEVICE = 'ctx.device';
    private const IP = 'ctx.ip';
    private const UA = 'ctx.ua';
    private const LOCATION = 'ctx.location';

    public static function setRequestId(string $id): void
    {
        Context::set(self::REQUEST_ID, $id);
    }

    public static function requestId(): string
    {
        return (string) (Context::get(self::REQUEST_ID) ?? '');
    }

    /**
     * @param array|null $user 当前登录用户数组（Model 属性），未登录为 null
     */
    public static function setUser(?array $user): void
    {
        Context::set(self::USER, $user);
    }

    public static function user(): ?array
    {
        return Context::get(self::USER);
    }

    public static function userId(): int
    {
        $user = self::user();
        return $user ? (int) ($user['id'] ?? 0) : 0;
    }

    public static function setDevice(?array $device): void
    {
        Context::set(self::DEVICE, $device);
    }

    public static function device(): ?array
    {
        return Context::get(self::DEVICE);
    }

    public static function deviceId(): int
    {
        $device = self::device();
        return $device ? (int) ($device['id'] ?? 0) : 0;
    }

    public static function setIp(string $ip): void
    {
        Context::set(self::IP, $ip);
    }

    public static function ip(): string
    {
        return (string) (Context::get(self::IP) ?? '');
    }

    public static function setUa(string $ua): void
    {
        Context::set(self::UA, $ua);
    }

    public static function ua(): string
    {
        return (string) (Context::get(self::UA) ?? '');
    }

    public static function setLocation(string $location): void
    {
        Context::set(self::LOCATION, $location);
    }

    public static function location(): string
    {
        return (string) (Context::get(self::LOCATION) ?? '');
    }

    public static function clear(): void
    {
        Context::destroy(self::REQUEST_ID);
        Context::destroy(self::USER);
        Context::destroy(self::DEVICE);
        Context::destroy(self::IP);
        Context::destroy(self::UA);
        Context::destroy(self::LOCATION);
    }
}
