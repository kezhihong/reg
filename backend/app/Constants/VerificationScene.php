<?php

declare(strict_types=1);

namespace App\Constants;

final class VerificationScene
{
    public const LOGIN_REGISTER = 1; // 登录注册二合一
    public const REGISTER = 2;       // 注册（预留）
    public const RESET_PASSWORD = 3; // 重置密码
    public const BIND_PHONE = 4;     // 绑定手机
    public const CHANGE_EMAIL = 5;   // 更换邮箱
    public const KYC_L1 = 6;         // KYC L1
}
