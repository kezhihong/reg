<?php

declare(strict_types=1);

namespace App\Provider;

use App\Constants\AppErrorCode;
use App\Exception\BusinessException;
use Psr\Log\LoggerInterface;

/**
 * GitHub OAuth Provider（docs/01 §5.1）：Authorization Code 模式。
 * dev 无真实凭据时走 mock 语义：code 以 `mock_` 前缀模拟成功。
 */
class GithubOAuthProvider implements OAuthProviderInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function authorizeUrl(array $params): string
    {
        $clientId = (string) env('OAUTH_GITHUB_CLIENT_ID', '');
        if ($clientId === '') {
            // dev mock：不跳转真实 GitHub（client_id=mock 必然失败），
            // 直接跳转本地回调模拟「用户已授权」（code 前缀 mock_ 由 getUserByCode 处理）。
            return $this->mockCallbackUrl($params['redirect_uri'], $params['state']);
        }
        return 'https://github.com/login/oauth/authorize?client_id=' . $clientId
            . '&redirect_uri=' . urlencode($params['redirect_uri'])
            . '&state=' . $params['state']
            . '&scope=read:user%20user:email';
    }

    /**
     * dev mock 回调地址：浏览器直接访问后端 callback（等价于 GitHub 授权后跳回）。
     */
    private function mockCallbackUrl(string $redirectUri, string $state): string
    {
        $parts = parse_url($redirectUri);
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $base . '/api/v1/oauth/github/callback?code=mock_' . bin2hex(random_bytes(4)) . '&state=' . $state;
    }

    public function getUserByCode(array $params): array
    {
        if (str_starts_with((string) $params['code'], 'mock_')) {
            // dev mock：固定账号（provider_user_id 不随 code 变化 →
            // 重复登录命中既有绑定直接登录，不会重复建号；docs/05 §2.1）
            return [
                'provider_user_id' => 'mock_github',
                'email' => 'github_mock@example.com',
                'name' => 'GitHub Mock',
                'avatar' => '',
            ];
        }

        $clientId = (string) env('OAUTH_GITHUB_CLIENT_ID', '');
        $secret = (string) env('OAUTH_GITHUB_CLIENT_SECRET', '');
        if ($clientId === '' || $secret === '') {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED, 'GitHub OAuth 未配置');
        }

        // code 换 token（服务端到服务端）
        $tokenResp = $this->httpPost('https://github.com/login/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'code' => $params['code'],
            'redirect_uri' => $params['redirect_uri'],
        ]);
        $token = $tokenResp['access_token'] ?? '';
        if ($token === '') {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }

        // 拉取用户信息
        $userResp = $this->httpGet('https://api.github.com/user', $token);
        $emailResp = $this->httpGet('https://api.github.com/user/emails', $token);
        $email = '';
        foreach ($emailResp as $item) {
            if (($item['primary'] ?? false) === true && ($item['verified'] ?? false) === true) {
                $email = $item['email'] ?? '';
                break;
            }
        }
        if ($email === '' && isset($userResp['email'])) {
            $email = (string) $userResp['email'];
        }

        return [
            'provider_user_id' => (string) $userResp['id'],
            'email' => $email,
            'name' => (string) ($userResp['name'] ?? $userResp['login'] ?? ''),
            'avatar' => (string) ($userResp['avatar_url'] ?? ''),
        ];
    }

    private function httpGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: mem-reg',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === false) {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }
        return json_decode($body, true) ?: [];
    }

    private function httpPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400 || $body === false) {
            throw new BusinessException(AppErrorCode::OAUTH_PROVIDER_FAILED);
        }
        return json_decode($body, true) ?: [];
    }
}
