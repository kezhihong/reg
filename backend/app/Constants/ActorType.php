<?php

declare(strict_types=1);

namespace App\Constants;

final class ActorType
{
    public const USER = 1;
    public const ADMIN = 2;
    public const SYSTEM = 3;
}

/**
 * 审计事件清单（docs/04 §7.1，action 常量与日志 action 同名）。
 */
