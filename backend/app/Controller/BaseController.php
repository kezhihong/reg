<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpServer\Contract\Annotation\Controller as ControllerAnnotation;
use Psr\Container\ContainerInterface;

/**
 * 控制器基类（docs/01 §3.3 统一响应 {code, message, data}）。
 * Controller 只做「取参 → 校验 → 调用 Service → 响应」，禁止 SQL/循环/复杂分支。
 */
abstract class BaseController
{
    #[Inject]
    protected ContainerInterface $container;

    public function __construct(
        protected RequestInterface $request,
        protected ResponseInterface $response
    ) {
    }

    protected function success(mixed $data = null, string $message = 'ok'): \Psr\Http\Message\ResponseInterface
    {
        $payload = [
            'code' => 0,
            'message' => $message,
            'data' => $data ?? (object) [],
        ];
        return $this->response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->json($payload);
    }

    /**
     * 成功响应 + 种 Cookie（docs/04 §3.1）：
     * Service 返回 ['cookies' => Cookie[], ...业务字段]，此处链式 withCookie 生成最终响应
     * （Hyperf Response 不可变，必须用返回值传递，兼容测试 Client 环境）。
     *
     * @param array $result 业务数据（可含 'cookies' 键，将从中剥离）
     */
    protected function successWithCookies(array $result, string $message = 'ok'): \Psr\Http\Message\ResponseInterface
    {
        $data = $result;
        unset($data['cookies']);
        $response = $this->success($data, $message);
        foreach ($result['cookies'] ?? [] as $cookie) {
            $response = $response->withCookie($cookie);
        }
        return $response;
    }

    /**
     * 清除认证 Cookie（登出/重置后，docs/04 §3.1）。
     * csrf_token 设置时 Path=/（供前端 document.cookie 读取），
     * 同时清掉旧版 Path=/api 的残留（防同名双 path 导致签名不一致）。
     */
    protected function clearAuthCookies(): \Psr\Http\Message\ResponseInterface
    {
        $secure = (bool) env('COOKIE_SECURE', false);
        $response = $this->response;
        foreach ([
            ['access_token', '/api', true],
            ['refresh_token', '/api/v1/auth/refresh', true],
            ['csrf_token', '/', false],
            ['csrf_token', '/api', false],
            ['device_key', '/', true],
        ] as [$name, $path, $httpOnly]) {
            $response = $response->withCookie(new \Hyperf\HttpMessage\Cookie\Cookie(
                $name, '', time() - 3600, $path, '', $secure, $httpOnly, false, 'Strict'
            ));
        }
        return $response;
    }

    /**
     * 成功响应 + 清除认证 Cookie（登出/重置/改密后，docs/02 §2.6/§2.9/§2.10）。
     */
    protected function successAfterClearCookies(string $message = 'ok'): \Psr\Http\Message\ResponseInterface
    {
        $payload = [
            'code' => 0,
            'message' => $message,
            'data' => (object) [],
        ];
        return $this->clearAuthCookies()
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    protected function clientIp(): string
    {
        $ip = $this->request->getHeaderLine('X-Forwarded-For');
        if ($ip !== '') {
            $first = explode(',', $ip)[0];
            if (filter_var(trim($first), FILTER_VALIDATE_IP)) {
                return trim($first);
            }
        }
        return $this->request->getServerParams()['remote_addr'] ?? '127.0.0.1';
    }

    protected function ua(): string
    {
        return (string) $this->request->getHeaderLine('User-Agent');
    }
}
