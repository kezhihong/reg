<?php

declare(strict_types=1);

namespace App\Provider;

use Psr\Log\LoggerInterface;

/**
 * Mock KYC Provider（docs/05 §3.3 KYC_PROVIDER=mock）：dev/test 固定通过。
 * 真实实现（RealKycProvider）由厂商接口接入，本系统仅对接抽象。
 */
class MockKycProvider implements KycProviderInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function verifyL2(string $realName, string $idCardNumber, string $countryCode, string $phone): array
    {
        $requestId = 'kyc_l2_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $this->logger->info('kyc_mock_l2', ['provider_request_id' => $requestId]);
        return [
            'success' => true,
            'provider_request_id' => $requestId,
            'message' => '',
        ];
    }

    public function verifyL3(string $providerSession): array
    {
        $requestId = 'kyc_l3_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $this->logger->info('kyc_mock_l3', ['provider_request_id' => $requestId]);
        return [
            'success' => true,
            'provider_request_id' => $requestId,
            'message' => '',
        ];
    }
}
