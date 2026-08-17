<?php

declare(strict_types=1);

namespace App\Controller;

use App\Constants\AppErrorCode;
use App\Constants\CookieNames;
use App\Constants\VerificationScene;
use App\Exception\BusinessException;
use App\Service\AuthService;
use App\Service\VerificationCodeService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 认证模块（docs/02 §2）：注册/登录/发码/刷新/登出/改密/重置/探测。
 */
#[Controller(prefix: '/api/v1/auth')]
class AuthController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected AuthService $authService,
        protected VerificationCodeService $codeService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 2.1 注册（docs/02 §2.1）：用户名 + 手机号 + 密码 + 短信验证码（注册即绑定手机）。
     * email 可选（预留邮箱登录场景）。
     */
    #[PostMapping(path: 'register')]
    public function register(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'username' => 'required|string|username',
            'email' => 'optional|string|email',
            'password' => 'required|string|password',
            'country_code' => 'required|string|country_code',
            'phone' => 'required|string|phone',
            'code' => 'required|string|len:6',
        ]);
        $result = $this->authService->register($input);
        return $this->successWithCookies($result);
    }

    /**
     * 2.2 账号/邮箱 + 密码登录。
     */
    #[PostMapping(path: 'login')]
    public function login(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'account' => 'required|string|max:128',
            'password' => 'required|string|max:72',
        ]);
        $result = $this->authService->login((string) $input['account'], (string) $input['password']);
        return $this->successWithCookies($result);
    }

    /**
     * 2.3 发送短信验证码（scene=5 更换邮箱时传 email，走邮箱通道，docs/02 §8.4）。
     */
    #[PostMapping(path: 'sms/send')]
    public function smsSend(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'country_code' => 'optional|string|country_code',
            'phone' => 'optional|string|phone',
            'email' => 'optional|string|email',
            'scene' => 'required|int|enum:1,2,3,4,5,6',
        ]);
        if ((int) $input['scene'] === VerificationScene::CHANGE_EMAIL) {
            if (empty($input['email'])) {
                throw new BusinessException(AppErrorCode::PARAM_INVALID, '更换邮箱场景需提供 email');
            }
            $result = $this->codeService->send('', '', (int) $input['scene'], (string) $input['email']);
        } else {
            if (empty($input['country_code']) || empty($input['phone'])) {
                throw new BusinessException(AppErrorCode::PARAM_INVALID, '手机号与区号必填');
            }
            $result = $this->codeService->send(
                (string) $input['country_code'],
                (string) $input['phone'],
                (int) $input['scene']
            );
        }
        return $this->success($result);
    }

    /**
     * 2.4 短信验证码登录（注册二合一）。
     */
    #[PostMapping(path: 'login/sms')]
    public function smsLogin(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'country_code' => 'required|string|country_code',
            'phone' => 'required|string|phone',
            'code' => 'required|string|len:6',
        ]);
        $result = $this->authService->smsLogin(
            (string) $input['country_code'],
            (string) $input['phone'],
            (string) $input['code']
        );
        return $this->successWithCookies($result);
    }

    /**
     * 2.5 刷新令牌（轮换）。
     */
    #[PostMapping(path: 'refresh')]
    public function refresh(): ResponseInterface
    {
        $result = $this->authService->refresh();
        return $this->successWithCookies($result);
    }

    /**
     * 2.6 登出当前设备。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[PostMapping(path: 'logout')]
    public function logout(): ResponseInterface
    {
        $deviceId = RequestContext::deviceId();
        $this->authService->logout($deviceId);
        return $this->successAfterClearCookies();
    }

    /**
     * 2.7 登出全部设备。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[PostMapping(path: 'logout-all')]
    public function logoutAll(): ResponseInterface
    {
        $this->authService->logoutAll(RequestContext::userId());
        return $this->successAfterClearCookies();
    }

    /**
     * 2.8 发送重置凭证（防枚举：账号不存在也返回成功）。
     */
    #[PostMapping(path: 'forgot-password')]
    public function forgotPassword(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'account' => 'required|string|max:128',
        ]);
        $result = $this->authService->forgotPassword((string) $input['account']);
        return $this->success($result);
    }

    /**
     * 2.9 重置密码。
     */
    #[PostMapping(path: 'reset-password')]
    public function resetPassword(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'account' => 'required|string|max:128',
            'code' => 'required|string|len:6',
            'new_password' => 'required|string|password',
        ]);
        $this->authService->resetPassword((string) $input['account'], (string) $input['code'], (string) $input['new_password']);
        return $this->successAfterClearCookies();
    }

    /**
     * 2.10 修改密码。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[PostMapping(path: 'change-password')]
    public function changePassword(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'old_password' => 'required|string|max:72',
            'new_password' => 'required|string|password',
        ]);
        $user = RequestContext::user();
        $this->authService->changePassword(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['old_password'],
            (string) $input['new_password']
        );
        return $this->successAfterClearCookies();
    }

    /**
     * 2.11 令牌有效性探测。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[GetMapping(path: 'check')]
    public function check(): ResponseInterface
    {
        $user = RequestContext::user();
        return $this->success([
            'valid' => true,
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
            ],
        ]);
    }
}
