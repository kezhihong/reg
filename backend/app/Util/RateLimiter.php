<?php

declare(strict_types=1);

namespace App\Util;

use App\Constants\AppErrorCode;
use App\Exception\RateLimitException;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * 分层限流器（docs/04 §4.1）：固定窗口计数，Redis 为主、sc_rate_limits 表降级。
 * 命中返回 429（RateLimitException，携带 X-RateLimit-* 元信息）。
 *
 * 用法：$rateLimiter->hit('ip:1.2.3.4:login', 20, 60, '登录');
 */
class RateLimiter
{
    public function __construct(
        protected Redis $redis,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * @param string $key        限流键（如 ip:{ip}:login / sms:+8613800000000）
     * @param int $limit         窗口内允许次数
     * @param int $windowSeconds 窗口秒
     * @param string $label      业务标签（用于频控文案，如「验证码发送」）
     */
    public function hit(string $key, int $limit, int $windowSeconds, string $label = ''): void
    {
        try {
            $this->hitRedis($key, $limit, $windowSeconds, $label);
            return;
        } catch (RateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis 不可用 → 降级 DB 表（docs/01 §6，生产推荐 Redis）
            $this->logger->warning('rate_limiter redis fallback: ' . $e->getMessage());
            $this->hitDb($key, $limit, $windowSeconds, $label);
        }
    }

    private function hitRedis(string $key, int $limit, int $windowSeconds, string $label): void
    {
        $redisKey = 'rate:' . $key;
        $count = $this->redis->incr($redisKey);
        if ($count === 1) {
            $this->redis->expire($redisKey, $windowSeconds);
        }
        $ttl = (int) $this->redis->ttl($redisKey);
        $resetAt = time() + max($ttl, 1);
        $remaining = max(0, $limit - $count);

        if ($count > $limit) {
            $this->throwRateLimit($limit, $remaining, $resetAt, $label);
        }
    }

    private function hitDb(string $key, int $limit, int $windowSeconds, string $label): void
    {
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $limitKey = substr($key, 0, 128);

        try {
            Db::table('rate_limits')->insertOrIgnore([
                'limit_key' => $limitKey,
                'window_start' => $windowStart,
                'count' => 1,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable) {
            // 并发首插冲突可忽略（唯一键兜底）
        }

        Db::table('rate_limits')
            ->where('limit_key', $limitKey)
            ->where('window_start', $windowStart)
            ->update(['count' => Db::raw('count + 1')]);

        $row = Db::table('rate_limits')
            ->where('limit_key', $limitKey)
            ->where('window_start', $windowStart)
            ->first();

        $c = (int) ($row->count ?? 1);
        $resetAt = $windowStart + $windowSeconds;
        if ($c > $limit) {
            $this->throwRateLimit($limit, max(0, $limit - $c), $resetAt, $label);
        }
    }

    private function throwRateLimit(int $limit, int $remaining, int $resetAt, string $label): void
    {
        throw new RateLimitException(
            AppErrorCode::RATE_LIMITED,
            ($label !== '' ? $label . '过于频繁' : '请求过于频繁') . '，请稍后再试',
            $limit,
            $remaining,
            $resetAt
        );
    }
}
