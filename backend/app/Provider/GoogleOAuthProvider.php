<?php

declare(strict_types=1);

namespace App\Provider;

use App\Constants\AppErrorCode;
use App\Exception\BusinessException;

/**
 * Google OAuth2 Provider（docs/01 §5.1）：Authorization Code + PKCE (S256)。
 * dev 无真实凭据时走 mock 语义（code 前缀 mock_）。
 */
class GoogleOAuthProvider implements OAuthProviderInterface
{
    public function authorizeUrl(array $params): string
    {
        $clientId = (string) env('OAUTH_GOOGLE_CLIENT_ID', '');
        if ($clientId === '') {
            // dev mock：直接跳转本地回调模拟「用户已授权」（docs/02 §3.1）
            $parts = parse_url($params['redirect_uri']);
            $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            return $base . '/api/v1/oauth/google/callback?code=mock_' . bin2hex(random_bytes(4)) . '&state=' . $params['state'];
        }
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $params['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $params['state'],
            'code_challenge' => $params['code_verifier'] ?? '',
            'code_challenge_method' => 'S256',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    public function getUserByCode(array $params): array
    {
        if (str_starts_with((string) $params['code'], 'mock_')) {
            // dev mock：固定账号（重复登录命中既有绑定直接登录）
            return [
                'provider_user_id' => 'mock_google',
                'email' => 'google_mock@example.com',
                'name' => 'Google Mock',
                'avatar' => '',
            ];
        }

        $clientId = (string) env('OAUTH_GOOGLE_CLIENT_ID', '');
        $secret = (string) env('OAUTH_GOOGLE_CLIENT_SECRET', '');
        if ($clientId === '' || $secret === '') {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED, 'Google OAuth 未配置');
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $secret,
                'code' => $params['code'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => $params['redirect_uri'],
                'code_verifier' => $params['code_verifier'] ?? '',
            ]),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === false) {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }
        $token = json_decode($body, true);
        $accessToken = $token['access_token'] ?? '';
        if ($accessToken === '') {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }

        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $user = json_decode((string) $body, true);
        if (! isset($user['sub'])) {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }

        return [
            'provider_user_id' => (string) $user['sub'],
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'avatar' => (string) ($user['picture'] ?? ''),
        ];
    }
}
