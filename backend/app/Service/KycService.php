<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\AuditAction;
use App\Constants\KycLevel;
use App\Constants\KycStatus;
use App\Constants\VerificationScene;
use App\Exception\BusinessException;
use App\Job\KycVerifyJob;
use App\Model\KycRecord;
use App\Model\User;
use App\Util\Masker;
use App\Util\RequestContext;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\DbConnection\Db;

/**
 * KYC 实名服务（docs/01 D6 / docs/02 §6）：
 * 等级单调递增 L0→L1→L2→L3；每次提交生成记录（只追加留痕）；
 * L2/L3 异步校验 + 回调/轮询双通道，provider_request_id 唯一索引兜底幂等。
 */
class KycService
{
    public function __construct(
        protected DriverFactory $driverFactory,
        protected VerificationCodeService $codeService,
        protected AuditService $auditService
    ) {
    }

    /**
     * 6.1 当前实名状态。
     */
    public function status(User $user): array
    {
        $latest = KycRecord::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        return [
            'kyc_level' => (int) $user->kyc_level,
            'is_phone_verified' => (int) $user->is_phone_verified === 1,
            'latest_record' => $latest === null ? null : [
                'id' => (int) $latest->id,
                'level' => (int) $latest->level,
                'status' => (int) $latest->status,
                'real_name' => Masker::realName((string) $latest->real_name),
                'id_card_number' => Masker::idCard((string) $latest->id_card_number),
                'created_at' => (int) $latest->created_at,
            ],
        ];
    }

    /**
     * 6.2 L1 手机号实名（docs/02 §6.2）：验证码（scene=6）→ is_phone_verified=1、kyc_level≥1。
     */
    public function l1(User $user, string $countryCode, string $phone, string $code): array
    {
        if ((int) $user->kyc_level >= KycLevel::L1) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '已完成 L1 实名');
        }
        // 须为本人已绑定手机号（docs/04 §5.5 双重交叉校验）
        if ((string) $user->country_code !== $countryCode || (string) $user->phone !== $phone) {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '手机号须为本人已绑定手机号');
        }

        $this->codeService->verify($countryCode, $phone, VerificationScene::KYC_L1, $code);

        $now = time();
        $record = new KycRecord();
        $record->user_id = (int) $user->id;
        $record->level = KycLevel::L1;
        $record->status = KycStatus::APPROVED;
        $record->real_name = '';
        $record->id_card_number = '';
        $record->country_code = $countryCode;
        $record->phone = $phone;
        $record->provider = 'sms';
        $record->provider_request_id = 'kyc_l1_' . $now . '_' . bin2hex(random_bytes(4));
        $record->request_detail = null;
        $record->result_detail = null;
        $record->callback_raw = null;
        $record->fail_reason = '';
        $record->reviewed_by = 0;
        $record->reviewed_at = 0;
        $record->created_at = $now;
        $record->updated_at = $now;
        $record->is_deleted = 0;
        $record->save();

        $user->is_phone_verified = 1;
        $user->kyc_level = max((int) $user->kyc_level, KycLevel::L1);
        $user->updated_at = $now;
        $user->save();

        $this->auditService->audit(AuditAction::KYC_SUBMITTED, (int) $user->id, 'user', ['level' => KycLevel::L1]);

        return $this->status($user);
    }

    /**
     * 6.3 提交三要素（docs/02 §6.3）：kyc_level ≥ 1；异步校验；同日同人 3 次。
     *
     * @return array{provider_request_id:string,status:int}
     */
    public function l2Submit(User $user, string $realName, string $idCardNumber, string $countryCode, string $phone): array
    {
        if ((int) $user->kyc_level < KycLevel::L1) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '请先完成 L1 手机号实名');
        }
        if ((int) $user->kyc_level >= KycLevel::L2) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '已完成 L2 实名');
        }
        $this->assertNoPending($user);

        // 同日同人 3 次（docs/04 §5.5 防撞库批量提交）
        $todayCount = KycRecord::query()
            ->where('user_id', $user->id)
            ->where('level', KycLevel::L2)
            ->where('created_at', '>=', strtotime('today'))
            ->count();
        if ($todayCount >= 3) {
            throw new BusinessException(AppErrorCode::RATE_LIMITED, '当日提交次数已达上限');
        }

        // 手机号须与本人绑定一致（docs/04 §5.5）
        if ((string) $user->phone === '' || (string) $user->country_code !== $countryCode || (string) $user->phone !== $phone) {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '手机号须为本人已绑定手机号');
        }

        $now = time();
        $record = new KycRecord();
        $record->user_id = (int) $user->id;
        $record->level = KycLevel::L2;
        $record->status = KycStatus::SUBMITTING;
        $record->real_name = $realName;
        $record->id_card_number = $idCardNumber;
        $record->country_code = $countryCode;
        $record->phone = $phone;
        $record->provider = (string) env('KYC_PROVIDER', 'mock');
        $record->provider_request_id = 'kyc_l2_' . $now . '_' . bin2hex(random_bytes(4));
        $record->request_detail = json_encode([
            'real_name' => Masker::realName($realName),
            'id_card_number' => Masker::idCard($idCardNumber),
            'country_code' => $countryCode,
            'phone' => Masker::phone($phone),
        ], JSON_UNESCAPED_UNICODE);
        $record->result_detail = null;
        $record->callback_raw = null;
        $record->fail_reason = '';
        $record->reviewed_by = 0;
        $record->reviewed_at = 0;
        $record->created_at = $now;
        $record->updated_at = $now;
        $record->is_deleted = 0;
        $record->save();

        $this->auditService->audit(AuditAction::KYC_SUBMITTED, (int) $user->id, 'user', ['level' => KycLevel::L2]);

        // 异步校验（事务外，docs/01 §5 第三方调用异步化）
        $this->driverFactory->get('default')->push(new KycVerifyJob((int) $record->id, KycLevel::L2));

        return ['provider_request_id' => (string) $record->provider_request_id, 'status' => (int) $record->status];
    }

    /**
     * 6.4 查询 L2 校验结果（轮询，docs/02 §6.4）。
     */
    public function l2Result(User $user, string $providerRequestId): array
    {
        $record = KycRecord::query()
            ->where('user_id', $user->id)
            ->where('provider_request_id', $providerRequestId)
            ->first();
        if ($record === null) {
            throw new BusinessException(AppErrorCode::KYC_RECORD_NOT_FOUND);
        }

        return [
            'provider_request_id' => (string) $record->provider_request_id,
            'status' => (int) $record->status,
            'fail_reason' => (string) $record->fail_reason,
            'reviewed_at' => (int) $record->reviewed_at,
            'kyc_level' => (int) $user->kyc_level,
        ];
    }

    /**
     * 6.5 发起 L3 活体（docs/02 §6.5）：kyc_level ≥ 2。
     */
    public function l3Submit(User $user, string $providerSession): array
    {
        if ((int) $user->kyc_level < KycLevel::L2) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '请先完成 L2 三要素实名');
        }
        if ((int) $user->kyc_level >= KycLevel::L3) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '已完成 L3 实名');
        }
        $this->assertNoPending($user);

        $todayCount = KycRecord::query()
            ->where('user_id', $user->id)
            ->where('level', KycLevel::L3)
            ->where('created_at', '>=', strtotime('today'))
            ->count();
        if ($todayCount >= 3) {
            throw new BusinessException(AppErrorCode::RATE_LIMITED, '当日提交次数已达上限');
        }

        $now = time();
        $record = new KycRecord();
        $record->user_id = (int) $user->id;
        $record->level = KycLevel::L3;
        $record->status = KycStatus::SUBMITTING;
        $record->real_name = '';
        $record->id_card_number = '';
        $record->country_code = '';
        $record->phone = '';
        $record->provider = (string) env('KYC_PROVIDER', 'mock');
        $record->provider_request_id = 'kyc_l3_' . $now . '_' . bin2hex(random_bytes(4));
        $record->request_detail = json_encode(['provider_session' => substr($providerSession, 0, 32)], JSON_UNESCAPED_UNICODE);
        $record->result_detail = null;
        $record->callback_raw = null;
        $record->fail_reason = '';
        $record->reviewed_by = 0;
        $record->reviewed_at = 0;
        $record->created_at = $now;
        $record->updated_at = $now;
        $record->is_deleted = 0;
        $record->save();

        $this->auditService->audit(AuditAction::KYC_SUBMITTED, (int) $user->id, 'user', ['level' => KycLevel::L3]);

        $this->driverFactory->get('default')->push(new KycVerifyJob((int) $record->id, KycLevel::L3));

        return ['provider_request_id' => (string) $record->provider_request_id, 'status' => (int) $record->status];
    }

    /**
     * 6.6 第三方回调（docs/02 §6.6）：验签（mock 通道校验签名头）→
     * (provider, provider_request_id) 唯一约束幂等落结果 → 通过则提升等级。
     */
    public function callback(string $provider, array $payload, string $signature): array
    {
        // 验签：mock 环境签名 = sha256(secret + body)（docs/02 §6.6，失败 14104）
        $secret = (string) env('KYC_PROVIDER_SECRET', 'mock-secret');
        $expected = hash('sha256', $secret . json_encode($payload));
        if (! hash_equals($expected, $signature)) {
            throw new BusinessException(AppErrorCode::KYC_CALLBACK_SIGN_FAILED);
        }

        $providerRequestId = (string) ($payload['provider_request_id'] ?? '');
        $result = (int) ($payload['status'] ?? 0);
        $record = KycRecord::query()
            ->where('provider', $provider)
            ->where('provider_request_id', $providerRequestId)
            ->first();
        if ($record === null) {
            throw new BusinessException(AppErrorCode::KYC_RECORD_NOT_FOUND);
        }

        // 幂等：仅提交中/复核中可流转（重复回调不改变状态，docs/04 §5.5）
        if ((int) $record->status === KycStatus::APPROVED || (int) $record->status === KycStatus::REJECTED) {
            return ['idempotent' => true];
        }

        $now = time();
        $record->status = $result === 1 ? KycStatus::APPROVED : KycStatus::REJECTED;
        $record->callback_raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $record->fail_reason = $result === 1 ? '' : (string) ($payload['message'] ?? '校验失败');
        $record->reviewed_at = $now;
        $record->updated_at = $now;
        $record->save();

        if ($result === 1) {
            // 通过则提升等级（单调递增不降级，docs/01 D6）
            Db::table('users')
                ->where('id', $record->user_id)
                ->where('kyc_level', '<', (int) $record->level)
                ->update(['kyc_level' => (int) $record->level, 'updated_at' => $now]);
        }

        $this->auditService->audit(AuditAction::KYC_CALLBACK_RECEIVED, (int) $record->user_id, 'kyc_record', [
            'record_id' => (int) $record->id,
            'status' => (int) $record->status,
        ]);

        return ['idempotent' => false];
    }

    /**
     * 6.7 实名记录列表（游标分页，docs/02 §6.7）。
     */
    public function records(User $user, int $cursor, int $perPage): array
    {
        $query = KycRecord::query()->where('user_id', $user->id);
        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();

        $items = [];
        foreach ($rows as $row) {
            if (count($items) >= $perPage) {
                break;
            }
            $items[] = [
                'id' => (int) $row->id,
                'level' => (int) $row->level,
                'status' => (int) $row->status,
                'real_name' => Masker::realName((string) $row->real_name),
                'id_card_number' => Masker::idCard((string) $row->id_card_number),
                'fail_reason' => (string) $row->fail_reason,
                'created_at' => (int) $row->created_at,
            ];
        }
        $nextCursor = count($rows) > $perPage ? (int) end($items)['id'] : null;

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    private function assertNoPending(User $user): void
    {
        $pending = KycRecord::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [KycStatus::SUBMITTING, KycStatus::REVIEWING])
            ->exists();
        if ($pending) {
            throw new BusinessException(AppErrorCode::KYC_LEVEL_DENIED, '存在审核中的实名申请，请等待结果');
        }
    }
}
