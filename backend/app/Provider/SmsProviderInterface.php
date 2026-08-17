<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * 短信发送抽象（docs/01 §5）：所有第三方调用异步化、经抽象接口，Mock/真实实现由配置路由。
 */
interface SmsProviderInterface
{
    /**
     * 发送短信验证码。
     *
     * @param string $countryCode E.164 区号（+86）
     * @param string $phone       手机号本体
     * @param string $code        6 位验证码明文（仅发送通道使用，禁止落日志）
     * @param string $scene       业务场景（VerificationScene）
     */
    public function send(string $countryCode, string $phone, string $code, string $scene): bool;
}
