<?php

declare(strict_types=1);

namespace App\Provider;

use Psr\Log\LoggerInterface;

/**
 * Mock 短信通道（dev/test）：验证码回显到日志（SMS_MOCK 门禁开启时可用）。
 * 生产环境由 SMS_PROVIDER 配置路由到真实通道；未配置/门禁关闭时调用方返回 403。
 */
class MockSmsProvider implements SmsProviderInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function send(string $countryCode, string $phone, string $code, string $scene): bool
    {
        // mock 回显仅 dev/test 门禁开启可用（docs/04 §4.2）
        if (! (bool) env('SMS_MOCK', false) || \App\Constants\Env::isProd()) {
            return false;
        }
        $this->logger->info('sms_mock_send', [
            'country_code' => $countryCode,
            'phone' => $phone,
            'scene' => $scene,
        ]);
        // 验证码仅写入日志（dev 调试用途；生产该实现不可达）
        $this->logger->notice('sms_mock_code', ['mock_code' => $code]);
        return true;
    }
}
