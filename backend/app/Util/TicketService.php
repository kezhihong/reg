<?php

declare(strict_types=1);

namespace App\Util;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 一次性签名票据（docs/04 §2.3）：totp_ticket 等无状态 HMAC 票据。
 * payload: uid / did / exp（5 分钟），密钥 JWT_TICKET_SECRET（独立于 JWT_SECRET）。
 */
class TicketService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = (string) env('JWT_TICKET_SECRET', '');
        $this->ttl = (int) env('TOKEN_TICKET_TTL', 300);
    }

    public function issue(int $userId, int $deviceId): string
    {
        $now = time();
        return JWT::encode([
            'uid' => $userId,
            'did' => $deviceId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ], $this->secret, 'HS256');
    }

    /**
     * 校验票据；无效/过期返回 null。
     *
     * @return array{uid:int,did:int}|null
     */
    public function parse(string $ticket): ?array
    {
        try {
            $decoded = JWT::decode($ticket, new Key($this->secret, 'HS256'));
            return [
                'uid' => (int) ($decoded->uid ?? 0),
                'did' => (int) ($decoded->did ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
