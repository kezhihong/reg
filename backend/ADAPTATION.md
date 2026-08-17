## 实现适配记录（Hyperf 3.1 差异，2026-08）

实现过程中发现文档（docs/01~05，基于设计阶段惯例）与 Hyperf 3.1 实际 API 存在版本落差，
以下为已落地且**已同步进代码/文档**的适配点（面试可讲）：

### 1. 依赖声明（composer.json）
`hyperf/framework` 在 3.1 是**精简 metapackage**，以下组件必须显式 require：
`hyperf/http-server` / `hyperf/http-message` / `hyperf/logger` / `hyperf/event` /
`hyperf/config`（ProviderConfig 所在）/ `hyperf/db-connection`（Model 基类）/
`hyperf/process`（自定义进程）/ `hyperf/command` / `hyperf/database` / `hyperf/redis` /
`hyperf/async-queue` / `hyperf/crontab`。

### 2. 全局函数命名空间化（helpers.php）
3.1 中 `env()` 等 helpers 移入 `Hyperf\Support` 命名空间；`app/helpers.php`
提供全局 `env()` 兼容包装（composer autoload.files 注册）。

### 3. Response 不可变 + Cookie 对象
- `Response::withCookie(Cookie $cookie)` 要求 **Cookie 对象**（第 8 参 `$raw`，
  第 9 参 `$sameSite`，不是字符串签名）；
- `with*` 返回**新实例**（不更新上下文）——Cookie 由 Service 返回数组、
  Controller 链式 `withCookie` 生成最终响应（`BaseController::successWithCookies`）。
- 测试环境（hyperf/testing）PSR-7 Request 不从 Cookie 头解析 cookie，
  自动化测试改为**真实 HTTP 集成测试**（curl 访问运行中的服务）。

### 4. 配置键格式
`listeners` / `commands` / `processes` 为**顶层列表**（`['ClassA', 'ClassB']`），
非嵌套 `['listeners' => [...]]`；logger `processors` 用数组格式
`['class' => X::class]` 才会经容器实例化。

### 5. PHP 语言层
- `match` 表达式的默认分支是 `default =>`（不带引号；`'default' =>` 是字符串键）。
- PSR-4：一个文件一个类（`Env.php` 多类已拆分）。

### 6. 运维
- 注解扫描缓存（`runtime/container/`）在代码变更后需清理
  （`rm -rf runtime/container/*` + 重启），否则路由/命令/事件注解不刷新。
- Swoole 5：Channel 只能在协程上下文使用（测试进程勿 enableCoroutine，
  会破坏原生 curl）。
- 限流阈值支持 `RATE_*` 环境变量覆盖（docs/05 §3.4），dev 放宽、生产收紧。
