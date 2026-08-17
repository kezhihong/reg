<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TotpService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 2FA 模块（docs/02 §4）：TOTP 启用/关闭/登录验证/恢复码。
 * 登录验证接口（4.4/4.5）为公开接口（携带 totp_ticket），经 AUTH_EXCEPT_PATHS 豁免。
 */
#[Controller(prefix: '/api/v1/2fa')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class TotpController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected TotpService $totpService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 4.1 生成 TOTP secret（不落库）。
     */
    #[PostMapping(path: 'enable/start')]
    public function enableStart(): ResponseInterface
    {
        $user = RequestContext::user();
        $result = $this->totpService->start(\App\Model\User::query()->find((int) $user['id']));
        return $this->success($result);
    }

    /**
     * 4.2 校验并启用（加密持久化 + 生成恢复码）。
     */
    #[PostMapping(path: 'enable/verify')]
    public function enableVerify(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'secret' => 'required|string|max:64',
            'code' => 'required|string|totp',
        ]);
        $user = RequestContext::user();
        $result = $this->totpService->enableVerify(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['secret'],
            (string) $input['code']
        );
        return $this->success($result);
    }

    /**
     * 4.3 关闭 2FA（动态码或恢复码二选一；触发全局吊销）。
     */
    #[PostMapping(path: 'disable')]
    public function disable(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'code' => 'optional|string|totp',
            'recovery_code' => 'optional|string|recovery_code',
        ]);
        $user = RequestContext::user();
        $this->totpService->disable(
            \App\Model\User::query()->find((int) $user['id']),
            isset($input['code']) ? (string) $input['code'] : null,
            isset($input['recovery_code']) ? (string) $input['recovery_code'] : null
        );
        return $this->success(null);
    }

    /**
     * 4.4 登录二次验证（动态码）。
     */
    #[PostMapping(path: 'login/verify')]
    public function loginVerify(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'totp_ticket' => 'required|string|max:512',
            'code' => 'required|string|totp',
        ]);
        $result = $this->totpService->loginVerify((string) $input['totp_ticket'], (string) $input['code']);
        return $this->successWithCookies($result);
    }

    /**
     * 4.5 登录时恢复码验证。
     */
    #[PostMapping(path: 'recovery/verify')]
    public function recoveryVerify(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'totp_ticket' => 'required|string|max:512',
            'recovery_code' => 'required|string|recovery_code',
        ]);
        $result = $this->totpService->loginRecoveryVerify((string) $input['totp_ticket'], (string) $input['recovery_code']);
        return $this->successWithCookies($result);
    }

    /**
     * 4.6 恢复码查询（首次完整返回，此后统计）。
     */
    #[GetMapping(path: 'recovery-codes')]
    public function recoveryCodes(): ResponseInterface
    {
        $user = RequestContext::user();
        $result = $this->totpService->recoveryCodes(\App\Model\User::query()->find((int) $user['id']));
        return $this->success($result);
    }

    /**
     * 4.6 按位单查恢复码（限频 60 秒/次）。
     */
    #[GetMapping(path: 'recovery-codes/{index}')]
    public function singleRecoveryCode(int $index): ResponseInterface
    {
        $user = RequestContext::user();
        $code = $this->totpService->singleRecoveryCode(\App\Model\User::query()->find((int) $user['id']), $index);
        return $this->success(['index' => $index, 'code' => $code]);
    }
}
