<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * OAuth 第三方抽象（docs/01 §5.1）：授权码模式 + PKCE。
 */
interface OAuthProviderInterface
{
    /**
     * 构建第三方授权跳转 URL。
     *
     * @param array{state:string,redirect_uri:string,code_verifier?:string} $params
     */
    public function authorizeUrl(array $params): string;

    /**
     * 授权码换令牌 + 拉取用户信息。
     *
     * @param array{code:string,redirect_uri:string,code_verifier?:string} $params
     * @return array{provider_user_id:string,email:string,name:string,avatar:string}
     * @throws \App\Exception\BusinessException 第三方失败（11102）
     */
    public function getUserByCode(array $params): array;
}
