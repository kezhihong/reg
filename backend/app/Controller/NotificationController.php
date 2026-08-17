<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 通知模块（docs/02 §9）。
 */
#[Controller(prefix: '/api/v1/notifications')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class NotificationController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected NotificationService $notificationService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 9.1 通知列表（游标分页，不返回 content）。
     */
    #[GetMapping(path: '')]
    public function list(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'cursor' => 'optional|int|min:0',
            'per_page' => 'optional|int|min:1|max:100',
        ]);
        $result = $this->notificationService->list(
            RequestContext::userId(),
            (int) ($input['cursor'] ?? 0),
            (int) ($input['per_page'] ?? 20)
        );
        return $this->success($result);
    }

    /**
     * 9.2 通知详情（仅本人）。
     */
    #[GetMapping(path: '{id}')]
    public function detail(int $id): ResponseInterface
    {
        $result = $this->notificationService->detail(RequestContext::userId(), $id);
        return $this->success($result);
    }
}
