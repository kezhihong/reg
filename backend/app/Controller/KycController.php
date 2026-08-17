<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\KycService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * KYC 实名模块（docs/02 §6）：L0→L3 状态机、回调幂等、记录列表。
 * 回调接口（6.6）为公开接口（第三方签名），经 AUTH_EXCEPT_PATHS 豁免。
 */
#[Controller(prefix: '/api/v1/kyc')]
#[Middleware(\App\Middleware\AuthMiddleware::class)]
class KycController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected KycService $kycService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 6.1 当前实名状态。
     */
    #[GetMapping(path: '')]
    public function status(): ResponseInterface
    {
        $user = RequestContext::user();
        return $this->success($this->kycService->status(\App\Model\User::query()->find((int) $user['id'])));
    }

    /**
     * 6.2 L1 手机号实名。
     */
    #[PostMapping(path: 'l1')]
    public function l1(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'country_code' => 'required|string|country_code',
            'phone' => 'required|string|phone',
            'code' => 'required|string|len:6',
        ]);
        $user = RequestContext::user();
        $result = $this->kycService->l1(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['country_code'],
            (string) $input['phone'],
            (string) $input['code']
        );
        return $this->success($result);
    }

    /**
     * 6.3 提交三要素（异步校验）。
     */
    #[PostMapping(path: 'l2/submit')]
    public function l2Submit(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'real_name' => 'required|string|min:2|max:32',
            'id_card_number' => 'required|string|min:15|max:32',
            'country_code' => 'required|string|country_code',
            'phone' => 'required|string|phone',
        ]);
        $user = RequestContext::user();
        $result = $this->kycService->l2Submit(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['real_name'],
            (string) $input['id_card_number'],
            (string) $input['country_code'],
            (string) $input['phone']
        );
        return $this->success($result);
    }

    /**
     * 6.4 查询 L2 校验结果（轮询）。
     */
    #[GetMapping(path: 'l2/result')]
    public function l2Result(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'provider_request_id' => 'required|string|max:64',
        ]);
        $user = RequestContext::user();
        $result = $this->kycService->l2Result(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['provider_request_id']
        );
        return $this->success($result);
    }

    /**
     * 6.5 发起 L3 活体。
     */
    #[PostMapping(path: 'l3/submit')]
    public function l3Submit(): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'provider_session' => 'required|string|min:8|max:512',
        ]);
        $user = RequestContext::user();
        $result = $this->kycService->l3Submit(
            \App\Model\User::query()->find((int) $user['id']),
            (string) $input['provider_session']
        );
        return $this->success($result);
    }

    /**
     * 6.6 第三方回调（验签 + 幂等；mock 环境签名头 X-Kyc-Signature）。
     */
    #[PostMapping(path: 'callback/{provider}')]
    public function callback(string $provider): ResponseInterface
    {
        $signature = (string) $this->request->getHeaderLine('X-Kyc-Signature');
        $payload = $this->request->all();
        $result = $this->kycService->callback($provider, $payload, $signature);
        return $this->success($result);
    }

    /**
     * 6.7 实名记录列表（游标分页）。
     */
    #[GetMapping(path: 'records')]
    public function records(): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'cursor' => 'optional|int|min:0',
            'per_page' => 'optional|int|min:1|max:100',
        ]);
        $user = RequestContext::user();
        $result = $this->kycService->records(
            \App\Model\User::query()->find((int) $user['id']),
            (int) ($input['cursor'] ?? 0),
            (int) ($input['per_page'] ?? 20)
        );
        return $this->success($result);
    }
}
