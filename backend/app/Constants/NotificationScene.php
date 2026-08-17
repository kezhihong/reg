<?php

declare(strict_types=1);

namespace App\Constants;

final class NotificationScene
{
    public const UNUSUAL_LOGIN = 1; // 异地告警
    public const RESET_PASSWORD = 2; // 重置密码
    public const SECURITY_NOTICE = 3; // 安全提醒
}
