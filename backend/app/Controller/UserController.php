<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 用户资料模块（docs/02 §8）。
 */
#[Controller(prefix: '/api/v1/user')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class UserController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected UserService $userService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 8.1 当前用户（全部脱敏）。
     */
    #[GetMapping(path: 'me')]
    public function me(): ResponseInterface
    {
        $user = RequestContext::user();
        return $this->success($this->userService->me(\App\Model\User::query()->find((int) $user['id'])));
    }

    /**
     * 8.2b 上传头像（multipart/form-data，字段 avatar；保存至服务器固定目录）。
     */
    #[PostMapping(path: 'me/avatar')]
    public function uploadAvatar(): ResponseInterface
    {
        $file = $this->request->file('avatar');
        if ($file === null) {
            throw new \App\Exception\BusinessException(\App\Constants\AppErrorCode::PARAM_INVALID, '请选择图片文件');
        }
        $user = RequestContext::user();
        $result = $this->userService->uploadAvatar(
            \App\Model\User::query()->find((int) $user['id']),
            $file
        );
        return $this->success($result);
    }

    /**
     * 8.2 修改资料。
     */
    #[PutMapping(path: 'me')]
    public function updateProfile(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'nickname' => 'optional|string|min:1|max:32',
            'avatar_url' => 'optional|string|max:500',
        ]);
        $user = RequestContext::user();
        $result = $this->userService->updateProfile(
            \App\Model\User::query()->find((int) $user['id']),
            (string) ($input['nickname'] ?? ''),
            (string) ($input['avatar_url'] ?? '')
        );
        return $this->success($result);
    }

    /**
     * 8.3 绑定/更换手机号。
     */
    #[PutMapping(path: 'me/phone')]
    public function bindPhone(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'country_code' => 'required|string|country_code',
            'phone' => 'required|string|phone',
            'code' => 'required|string|len:6',
        ]);
        $user = RequestContext::user();
        $result = $this->userService->bindPhone(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['country_code'],
            (string) $input['phone'],
            (string) $input['code']
        );
        return $this->success($result);
    }

    /**
     * 8.4 绑定/更换邮箱。
     */
    #[PutMapping(path: 'me/email')]
    public function bindEmail(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'email' => 'required|string|email',
            'code' => 'required|string|len:6',
        ]);
        $user = RequestContext::user();
        $result = $this->userService->bindEmail(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['email'],
            (string) $input['code']
        );
        return $this->success($result);
    }
}
