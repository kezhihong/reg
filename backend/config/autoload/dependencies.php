<?php

declare(strict_types=1);

use App\Provider\EmailProviderInterface;
use App\Provider\KycProviderInterface;
use App\Provider\MockEmailProvider;
use App\Provider\MockKycProvider;
use App\Provider\MockSmsProvider;
use App\Provider\SmsProviderInterface;

return [
    // 第三方抽象接口 → 实现路由（docs/01 §5）：SMS_PROVIDER / EMAIL_PROVIDER / KYC_PROVIDER 配置
    SmsProviderInterface::class => MockSmsProvider::class,
    EmailProviderInterface::class => MockEmailProvider::class,
    KycProviderInterface::class => MockKycProvider::class,
];
