<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * 邮件发送抽象（docs/01 §5）：异地告警、重置密码邮件。
 */
interface EmailProviderInterface
{
    /**
     * @param string $to      收件邮箱
     * @param string $title   标题
     * @param string $content 正文（纯文本）
     */
    public function send(string $to, string $title, string $content): bool;
}
