<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * KYC 第三方抽象（docs/01 §5.2 / D6）：L2 三要素校验、L3 活体校验。
 */
interface KycProviderInterface
{
    /**
     * L2 三要素校验（姓名 + 证件号 + 手机号）。
     *
     * @return array{success:bool,provider_request_id:string,message:string}
     */
    public function verifyL2(string $realName, string $idCardNumber, string $countryCode, string $phone): array;

    /**
     * L3 活体校验（前端 SDK 采集的会话凭证）。
     *
     * @return array{success:bool,provider_request_id:string,message:string}
     */
    public function verifyL3(string $providerSession): array;
}
