<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Constants\AppErrorCode;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 兜底异常处理器（设计规范 §1.6 [必须]）：
 * 5xx 响应禁止返回堆栈 / SQL / 框架内部信息，异常详情只进服务端日志。
 */
class AppExceptionHandler extends HttpExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        // 日志脱敏：不输出密码/令牌/验证码等（docs/04 §7.2）
        $this->logger->error(sprintf(
            '[%s] %s in %s:%d',
            get_class($throwable),
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ));
        if (env('APP_DEBUG', false)) {
            $this->logger->error('[trace] ' . $throwable->getTraceAsString());
        }

        $payload = [
            'code' => AppErrorCode::BUSINESS_RULE,
            'message' => '系统繁忙，请稍后再试',
            'data' => (object) [],
        ];

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
