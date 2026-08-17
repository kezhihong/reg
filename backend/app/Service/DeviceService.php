<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\AuditAction;
use App\Constants\CookieNames;
use App\Constants\RefreshRevokedReason;
use App\Exception\BusinessException;
use App\Model\RefreshToken;
use App\Model\UserDevice;
use App\Util\RequestContext;
use App\Util\UserAgentParser;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * 设备服务（docs/01 §4.3 D2）：服务端生成 device_key、列表、踢设备、信任标记。
 */
class DeviceService
{
    public function __construct(
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected AuditService $auditService
    ) {
    }

    /**
     * 设备归并（docs/01 §4.1）：读 device_key Cookie → 存在则更新设备行，否则新建。
     * 服务端生成指纹（不信任客户端），32 字节随机 hex。
     *
     * @return array{device:UserDevice, deviceKey:string, isNew:bool}
     */
    public function getOrCreateDevice(int $userId): array
    {
        $now = time();
        $cookies = $this->request->getCookieParams();
        $deviceKey = (string) ($cookies[CookieNames::DEVICE] ?? '');

        $device = null;
        if ($deviceKey !== '') {
            $device = UserDevice::query()
                ->where('user_id', $userId)
                ->where('device_key', $deviceKey)
                ->where('is_deleted', 0)
                ->first();
        }

        if ($device !== null) {
            // 已吊销的设备不可复用 → 生成新指纹（原设备会话保持吊销）
            if ((int) $device->revoked_at > 0) {
                $device = null;
            } else {
                $device->last_active_at = $now;
                $device->last_ip = RequestContext::ip();
                $device->updated_at = $now;
                $device->save();
                return ['device' => $device, 'deviceKey' => $deviceKey, 'isNew' => false];
            }
        }

        // 新建设备
        $deviceKey = bin2hex(random_bytes(32));
        $device = new UserDevice();
        $device->user_id = $userId;
        $device->device_key = $deviceKey;
        $device->device_name = UserAgentParser::parse(RequestContext::ua());
        $device->user_agent = substr(RequestContext::ua(), 0, 500);
        $device->last_ip = RequestContext::ip();
        $device->last_ip_location = '';
        $device->is_trusted = 0;
        $device->last_active_at = $now;
        $device->revoked_at = 0;
        $device->created_at = $now;
        $device->updated_at = $now;
        $device->is_deleted = 0;
        $device->save();

        return ['device' => $device, 'deviceKey' => $deviceKey, 'isNew' => true];
    }

    /**
     * 构造 device_key Cookie（1 年，Path=/, HttpOnly, SameSite=Strict，docs/04 §3.1）。
     * 返回 Cookie 对象由 Controller 链式写入响应（兼容测试 Client 环境）。
     */
    public function deviceCookie(string $deviceKey): \Hyperf\HttpMessage\Cookie\Cookie
    {
        return new \Hyperf\HttpMessage\Cookie\Cookie(
            CookieNames::DEVICE,
            $deviceKey,
            time() + 31536000,
            '/',
            '',
            (bool) env('COOKIE_SECURE', false),
            true,
            false,
            'Strict'
        );
    }

    /**
     * 设备列表（游标分页，docs/02 §5.1）。
     */
    public function list(int $userId, int $cursor, int $perPage): array
    {
        $query = UserDevice::query()
            ->where('user_id', $userId)
            ->where('is_deleted', 0);
        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();

        $items = [];
        $currentDeviceId = RequestContext::deviceId();
        foreach ($rows as $row) {
            if (count($items) >= $perPage) {
                break;
            }
            $items[] = [
                'id' => (int) $row->id,
                'device_name' => (string) $row->device_name,
                'last_ip' => (string) $row->last_ip,
                'last_ip_location' => (string) $row->last_ip_location,
                'is_current' => (int) $row->id === $currentDeviceId,
                'is_trusted' => (int) $row->is_trusted === 1,
                'last_active_at' => (int) $row->last_active_at,
            ];
        }

        $nextCursor = count($rows) > $perPage ? (int) end($items)['id'] : null;
        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /**
     * 踢设备（docs/02 §5.2）：条件更新 revoked_at（防重复踢）+ 吊销该设备全部 refresh + 审计。
     * 踢当前设备等同登出（返回 is_current 标记由 Controller 清 Cookie）。
     */
    public function kick(int $userId, int $deviceId): void
    {
        $device = $this->findOwned($userId, $deviceId);
        if ((int) $device->revoked_at > 0) {
            throw new BusinessException(AppErrorCode::DEVICE_REVOKED);
        }

        $now = time();
        $updated = UserDevice::query()
            ->where('id', $deviceId)
            ->where('user_id', $userId)
            ->where('revoked_at', 0)
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
        if ($updated === 0) {
            throw new BusinessException(AppErrorCode::DEVICE_REVOKED);
        }

        RefreshToken::query()
            ->where('device_id', $deviceId)
            ->where('revoked_at', 0)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => RefreshRevokedReason::KICKED,
                'updated_at' => $now,
            ]);

        $this->auditService->audit(AuditAction::DEVICE_KICK, $deviceId, 'device', [
            'device_name' => (string) $device->device_name,
        ]);
    }

    /**
     * 设置信任设备（docs/02 §5.3）。
     */
    public function setTrusted(int $userId, int $deviceId, bool $trusted): array
    {
        $device = $this->findOwned($userId, $deviceId);
        if ((int) $device->revoked_at > 0) {
            throw new BusinessException(AppErrorCode::DEVICE_REVOKED);
        }
        $device->is_trusted = $trusted ? 1 : 0;
        $device->updated_at = time();
        $device->save();

        $this->auditService->audit(AuditAction::DEVICE_TRUST_CHANGED, $deviceId, 'device', [
            'is_trusted' => $trusted,
        ]);

        return ['id' => (int) $device->id, 'is_trusted' => $trusted];
    }

    private function findOwned(int $userId, int $deviceId): UserDevice
    {
        $device = UserDevice::query()
            ->where('id', $deviceId)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if ($device === null) {
            throw new BusinessException(AppErrorCode::DEVICE_NOT_FOUND);
        }
        return $device;
    }
}
