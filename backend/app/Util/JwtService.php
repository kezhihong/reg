<?php

declare(strict_types=1);

namespace App\Util;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Access Token 服务（docs/01 §4.2 D1）：无状态 JWT（HS256），
 * payload: uid / ver（sc_users.token_version 快照）/ did（device_id）/ iat / exp。
 * 密钥来自环境变量 JWT_SECRET；不做黑名单，即时吊销依赖短时效 + token_version。
 */
class JwtService
{
    private string $secret;
    private int $ttl;

    public function __construct()
    {
        $this->secret = (string) env('JWT_SECRET', '');
        $this->ttl = (int) env('TOKEN_ACCESS_TTL', 900);
    }

    public function issue(int $userId, int $tokenVersion, int $deviceId): string
    {
        $now = time();
        $payload = [
            'uid' => $userId,
            'ver' => $tokenVersion,
            'did' => $deviceId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ];
        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * 校验并解析；失败返回 null（expired / 签名错误统一视为无效）。
     * 调用方需额外比对 ver（token_version）与用户状态（AuthMiddleware 职责）。
     *
     * @return array{uid:int,ver:int,did:int}|null
     */
    public function parse(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return [
                'uid' => (int) ($decoded->uid ?? 0),
                'ver' => (int) ($decoded->ver ?? 0),
                'did' => (int) ($decoded->did ?? 0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * CSRF 双提交校验值（docs/04 §3.2）：X-CSRF-Token = Access JWT 签名片段。
     * 服务端无状态比对：解析 Cookie 中 Access 的签名段是否与请求头一致。
     */
    public static function csrfValue(string $accessToken): string
    {
        $parts = explode('.', $accessToken);
        return $parts[2] ?? '';
    }

    public function ttl(): int
    {
        return $this->ttl;
    }
}
