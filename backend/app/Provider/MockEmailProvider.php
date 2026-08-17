<?php

declare(strict_types=1);

namespace App\Provider;

use Psr\Log\LoggerInterface;

/**
 * Mock 邮件通道：输出日志（dev/test）。生产由 SMTP_* 配置路由到真实 SMTP。
 */
class MockEmailProvider implements EmailProviderInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function send(string $to, string $title, string $content): bool
    {
        $this->logger->info('email_mock_send', [
            'to' => $to,
            'title' => $title,
        ]);
        return true;
    }
}
