<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OAuthService;
use App\Util\RequestContext;
use App\Util\Validator;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuth 模块（docs/02 §3）：授权发起 / 回调 / 绑定 / 解绑。
 */
#[Controller(prefix: '/api/v1/oauth')]
class OAuthController extends BaseController
{
    public function __construct(
        RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        protected OAuthService $oauthService
    ) {
        parent::__construct($request, $response);
    }

    /**
     * 3.1 发起第三方授权（302 跳转；登录/绑定双场景）。
     */
    #[Middleware(\App\Middleware\AuthOptionalMiddleware::class)]
    #[GetMapping(path: '{provider}/authorize')]
    public function authorize(string $provider): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'redirect_uri' => 'required|string|max:500',
        ]);
        $user = RequestContext::user();
        $result = $this->oauthService->authorize(
            $provider,
            (string) $input['redirect_uri'],
            $user !== null ? (int) $user['id'] : null
        );
        return $this->response
            ->withStatus(302)
            ->withHeader('Location', $result['redirect_url'])
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->json(['code' => 0, 'message' => 'ok', 'data' => $result]);
    }

    /**
     * 3.2 第三方回调（302 回前端，?code=0 成功 / ?code=错误码 失败）。
     */
    #[Middleware(\App\Middleware\AuthOptionalMiddleware::class)]
    #[GetMapping(path: '{provider}/callback')]
    public function callback(string $provider): ResponseInterface
    {
        $input = Validator::validate($this->request->query(), [
            'code' => 'required|string|max:256',
            'state' => 'required|string|len:64',
        ]);
        $result = $this->oauthService->callback($provider, (string) $input['code'], (string) $input['state']);
        $response = $this->response
            ->withStatus(302)
            ->withHeader('Location', $result['redirect_url'])
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->json(['code' => 0, 'message' => 'ok', 'data' => $result]);
        // 登录成功种 Cookie（docs/04 §3.1；OAuth 登录与普通登录同口径）
        foreach ($result['cookies'] ?? [] as $cookie) {
            $response = $response->withCookie($cookie);
        }
        return $response;
    }

    /**
     * 3.3 登录态绑定第三方。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[PostMapping(path: '{provider}/bind')]
    public function bind(string $provider): ResponseInterface
    {
        $input = Validator::validate($this->request->all(), [
            'redirect_uri' => 'required|string|max:500',
        ]);
        $user = RequestContext::user();
        $result = $this->oauthService->bind($provider, (string) $input['redirect_uri'], (int) $user['id']);
        return $this->success($result);
    }

    /**
     * 3.4 解绑第三方。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[DeleteMapping(path: '{provider}/unbind')]
    public function unbind(string $provider): ResponseInterface
    {
        $user = RequestContext::user();
        $this->oauthService->unbind($provider, (int) $user['id']);
        return $this->success(null);
    }

    /**
     * 辅助：已绑定第三方列表（前端「安全设置」）。
     */
    #[Middleware(\App\Middleware\AuthMiddleware::class)]
    #[GetMapping(path: 'bound')]
    public function bound(): ResponseInterface
    {
        $user = RequestContext::user();
        return $this->success(['items' => $this->oauthService->listBound((int) $user['id'])]);
    }
}
