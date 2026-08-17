<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DeviceService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 设备模块（docs/02 §5）：设备列表 / 踢设备 / 信任设备。
 */
#[Controller(prefix: '/api/v1/devices')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class DeviceController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected DeviceService $deviceService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 5.1 设备列表（游标分页）。
     */
    #[GetMapping(path: '')]
    public function list(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'cursor' => 'optional|int|min:0',
            'per_page' => 'optional|int|min:1|max:100',
        ]);
        $cursor = (int) ($input['cursor'] ?? 0);
        $perPage = (int) ($input['per_page'] ?? 20);
        $result = $this->deviceService->list(RequestContext::userId(), $cursor, $perPage);
        return $this->success($result);
    }

    /**
     * 5.2 踢设备（吊销 + 该设备全部 refresh + 审计）。
     */
    #[DeleteMapping(path: '{id}')]
    public function kick(int $id): ResponseInterface
    {
        $userId = RequestContext::userId();
        $this->deviceService->kick($userId, $id);
        // 踢当前设备等同登出（docs/02 §5.2 同步清 Cookie）
        if ($id === RequestContext::deviceId()) {
            return $this->successAfterClearCookies();
        }
        return $this->success(null);
    }

    /**
     * 5.3 设置信任设备。
     */
    #[PutMapping(path: '{id}/trust')]
    public function trust(int $id): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'trusted' => 'required|bool',
        ]);
        $trusted = in_array($input['trusted'], [true, 1, '1', 'true'], true);
        $result = $this->deviceService->setTrusted(RequestContext::userId(), $id, $trusted);
        return $this->success($result);
    }
}
