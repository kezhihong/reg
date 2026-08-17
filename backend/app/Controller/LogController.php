<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 日志模块（docs/02 §7）：我的登录日志 / 审计日志（管理端预留）。
 */
#[Controller(prefix: '/api/v1/logs')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class LogController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected AuditService $auditService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 7.1 我的登录日志（游标分页，docs/02 §7.1）。
     */
    #[GetMapping(path: 'login')]
    public function loginLogs(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'cursor' => 'optional|int|min:0',
            'per_page' => 'optional|int|min:1|max:100',
        ]);
        $userId = RequestContext::userId();
        $cursor = (int) ($input['cursor'] ?? 0);
        $perPage = (int) ($input['per_page'] ?? 20);

        $query = Db::table('login_logs')->where('user_id', $userId);
        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();

        $items = [];
        $deviceNames = $this->deviceNames(array_map(fn ($r) => (int) $r->device_id, iterator_to_array($rows)));
        foreach ($rows as $row) {
            if (count($items) >= $perPage) {
                break;
            }
            $items[] = [
                'id' => (int) $row->id,
                'login_type' => (int) $row->login_type,
                'is_success' => (int) $row->is_success === 1,
                'fail_reason' => (int) $row->fail_reason,
                'ip' => (string) $row->ip,
                'ip_location' => (string) $row->ip_location,
                'device_name' => $deviceNames[(int) $row->device_id] ?? '',
                'is_unusual' => (int) $row->is_unusual === 1,
                'created_at' => (int) $row->created_at,
            ];
        }
        $nextCursor = count($rows) > $perPage ? (int) end($items)['id'] : null;

        return $this->success(['items' => $items, 'next_cursor' => $nextCursor]);
    }

    /**
     * 7.2 审计日志（管理端：is_admin=1，docs/02 §7.2；权限由 AdminMiddleware 保障）。
     */
    #[Middleware(\App\Middleware\AdminMiddleware::class)]
    #[GetMapping(path: 'audit')]
    public function auditLogs(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'cursor' => 'optional|int|min:0',
            'per_page' => 'optional|int|min:1|max:100',
            'action' => 'optional|string|max:64',
        ]);
        $cursor = (int) ($input['cursor'] ?? 0);
        $perPage = (int) ($input['per_page'] ?? 20);

        $query = Db::table('audit_logs');
        if (! empty($input['action'])) {
            $query->where('action', (string) $input['action']);
        }
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
                'action' => (string) $row->action,
                'actor_id' => (int) $row->actor_id,
                'target_type' => (string) $row->target_type,
                'target_id' => (int) $row->target_id,
                'ip' => (string) $row->ip,
                'request_id' => (string) $row->request_id,
                'detail_json' => $row->detail_json !== null ? json_decode((string) $row->detail_json, true) : null,
                'created_at' => (int) $row->created_at,
            ];
        }
        $nextCursor = count($rows) > $perPage ? (int) end($items)['id'] : null;

        return $this->success(['items' => $items, 'next_cursor' => $nextCursor]);
    }

    /**
     * 批量取设备名（防 N+1，设计规范 §1.9 [必须]）。
     *
     * @param int[] $deviceIds
     * @return array<int,string>
     */
    private function deviceNames(array $deviceIds): array
    {
        $ids = array_values(array_unique(array_filter($deviceIds, fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = Db::table('user_devices')->whereIn('id', $ids)->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = (string) $row->device_name;
        }
        return $map;
    }
}
