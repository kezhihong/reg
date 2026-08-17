# 02-API 文档

> 生产级登录注册系统 · 接口契约（唯一权威出处）
> 遵守 `设计规范.md` §1.5 输入校验、§1.6 异常与错误处理、§1.9 性能（分页上限）、§1.10 配置门禁；设计依据 `01-架构设计.md`（D1–D9）。

## 1. 通用约定

### 1.1 基础信息

- Base URL：`/api/v1`（生产经 Nginx 反代，同源部署）
- 协议：HTTPS（生产强制）；开发环境 HTTP（Compose 内网）
- 内容类型：`application/json; charset=utf-8`（上传/文件场景除外）
- 全部请求需携带响应头 `X-Request-Id`（服务端生成或透传客户端 `X-Request-Id`），贯穿日志与审计

### 1.2 统一响应格式

```json
{
  "code": 0,
  "message": "ok",
  "data": {}
}
```

- `code=0` 业务成功；`code≠0` 业务失败，`message` 为用户可读文案
- HTTP 状态码与业务 code 的关系：业务失败时 HTTP 状态码遵循下表语义（规范 §1.6）

| HTTP 状态码 | 语义 |
|---|---|
| 200 | 成功 |
| 400 | 参数错误 / 校验失败（code 10000） |
| 401 | 未认证 / 令牌无效（code 10001） |
| 403 | 无权限（含演示开关未开启）（code 10002） |
| 404 | 资源不存在（含未匹配路由 JSON 404）（code 10003） |
| 409 | 冲突（唯一性、重复操作）（code 10004） |
| 419 | CSRF 校验失败（code 10005） |
| 422 | 语义 / 业务规则不满足（code 10008） |
| 423 | 资源被锁定（账号锁定）（code 10007） |
| 429 | 限流 / 频控（code 10006） |

5xx 响应禁止返回堆栈 / SQL / 框架内部信息，异常详情只进服务端日志。

### 1.3 业务错误码总表

| 分组 | 错误码 | 含义 |
|---|---|---|
| 通用 | 10000 | 参数错误 / 校验失败 |
| 通用 | 10001 | 未认证 / 令牌无效 |
| 通用 | 10002 | 无权限 |
| 通用 | 10003 | 资源不存在 |
| 通用 | 10004 | 冲突（唯一性、重复操作） |
| 通用 | 10005 | CSRF 校验失败 |
| 通用 | 10006 | 频率限制 |
| 通用 | 10007 | 账号锁定 |
| 通用 | 10008 | 业务规则不满足 |
| 认证 101xx | 10101 | 账号或密码错误（统一文案，防枚举） |
| 认证 | 10102 | 验证码错误 |
| 认证 | 10103 | 验证码过期 |
| 认证 | 10104 | 验证码已使用 |
| 认证 | 10105 | 验证码发送过于频繁 |
| 认证 | 10106 | 需要二次验证（2FA） |
| 认证 | 10107 | 账号已被禁用 |
| 认证 | 10108 | 重置凭证无效或过期 |
| 认证 | 10109 | 原密码错误 |
| OAuth 111xx | 11101 | state 校验失败 |
| OAuth | 11102 | 第三方授权失败 |
| OAuth | 11103 | 该第三方账号已绑定 |
| OAuth | 11104 | 解绑失败（保留最后登录凭证） |
| 2FA 121xx | 12101 | 动态码错误 |
| 2FA | 12102 | 动态码已使用（重放） |
| 2FA | 12103 | 恢复码无效或已用 |
| 2FA | 12104 | 2FA 未启用 |
| 2FA | 12105 | 2FA 已启用 |
| 设备 131xx | 13101 | 设备不存在 |
| 设备 | 13102 | 设备已吊销 |
| KYC 141xx | 14101 | 等级状态不允许该操作 |
| KYC | 14102 | 三要素校验失败 |
| KYC | 14103 | 活体校验失败 |
| KYC | 14104 | 回调验签失败 |
| KYC | 14105 | 实名记录不存在 |
| 日志 151xx | 15101 | 无权限查询审计日志 |
| 用户 161xx | 16101 | 用户名 / 邮箱 / 手机号已被占用 |
| 用户 | 16102 | 手机号已绑定 |
| 用户 | 16103 | 邮箱已绑定 |
| 通知 171xx | 17101 | 通知不存在 |

### 1.4 认证方式

- **Cookie 凭据**（规范 §1.7 强制，令牌不出现在请求/响应体）：
  - Access Cookie：`SameSite=Strict`、`Secure`（生产）、`Path=/api`，时效 15 分钟
  - Refresh Cookie：`SameSite=Strict`、`Secure`（生产）、`Path=/api/v1/auth/refresh`，时效 30 天
- 除「公开接口」（注册、登录、发码、重置、OAuth 回调、KYC 回调）外，均需有效 Access（401 未认证）
- 前端 axios `withCredentials: true`；401 自动静默刷新（见 `01-架构设计.md` §7.2）

### 1.5 限流约定

- 命中限流返回 429，并在响应头给出：`X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset`（窗口重置 Unix 秒）
- 各接口限流值见接口条目「限流」字段；全局矩阵见 `04-安全设计.md` §4

### 1.6 分页规范

- 列表接口统一**游标分页**（深分页禁止 OFFSET，规范 §2.6）：`?cursor=<上一页最后一条 id>&per_page=20`
- `per_page` 默认 20，**下限 1、上限 100**（规范 §1.9 禁止无界查询）
- 响应：`data: {items: [...], next_cursor: 12345 | null}`（null 表示无下一页）

### 1.7 脱敏规则（规范 §1.7 / §2.4）

接口返回的个人信息一律脱敏，服务端为唯一脱敏源（前端仅展示）：

| 类型 | 规则 | 示例 |
|---|---|---|
| 手机号 | 前 3 后 4 | `138****1234` |
| 邮箱 | 首字符 + `***` + @ 后域名 | `a***@example.com` |
| 证件号 | 前 4 后 4 | `1101**********1234` |
| 真实姓名 | 保留姓 | `张*` |

### 1.8 幂等约定

- 写接口如需幂等，客户端携带 `X-Idempotency-Key`（UUID）请求头；服务端对同一 key 在同一窗口（默认 10 分钟）内返回首次结果
- 服务端侧幂等兜底：唯一约束 / 条件更新（具体见各接口「幂等」字段）

---

## 2. 认证模块

### 2.1 POST /auth/register — 注册

**鉴权**：无　**限流**：IP 10 次/分钟　**幂等**：唯一约束兜底（username/phone/email）

> 实现约定（2026-08 更新）：注册即绑定手机号（手机号+验证码必填），
> 注册成功 `is_phone_verified=1`、`kyc_level≥1`（L1 实名随注册完成）。

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| username | string | 是 | 3–32 位，字母数字下划线 |
| email | string | 否 | 标准邮箱格式（可选，预留邮箱登录场景） |
| password | string | 是 | 8–72 位（bcrypt 上限），须含字母与数字 |
| country_code | string | 是 | E.164 区号，如 +86 |
| phone | string | 是 | 手机号本体（不含 +），11–15 位数字 |
| code | string | 是 | 短信验证码（scene=1 登录注册二合一） |

**响应 200**（注册成功即建立登录态，种双 Cookie）：

```json
{ "code": 0, "message": "ok", "data": { "user": { "id": 1001, "username": "alice", "phone": "138****1234", "kyc_level": 0, "totp_enabled": false } } }
```

**错误码**：10000 / 10105 / 16101（409）/ 10102 / 10103 / 10104

### 2.2 POST /auth/login — 账号（用户名/邮箱/手机号）+ 密码登录

**鉴权**：无　**限流**：IP 20 次/分钟；账号 10 次失败/15 分钟　**幂等**：否

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| account | string | 是 | 用户名 / 邮箱 / 手机号（三合一入口，服务端分别查唯一索引）；手机号支持 `+8613800000000` 格式（自带区号）或纯数字（按 `LOGIN_DEFAULT_COUNTRY_CODE` 默认区号，默认 +86） |
| password | string | 是 | 密码 |

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": {
  "need_totp": false,
  "user": { "id": 1001, "username": "alice", "totp_enabled": false, "kyc_level": 1 }
} }
```

2FA 已启用时 `need_totp=true`，`data` 附 `totp_ticket`（无状态签名票据，5 分钟有效，payload 含 uid/did）：

```json
{ "code": 0, "message": "ok", "data": { "need_totp": true, "totp_ticket": "eyJhbGciOiJIUzI1NiJ9..." } }
```

**错误码**：10000 / 10101（401，统一文案：账号不存在/密码错误/禁用统一提示，防枚举）/ 10007（423 锁定，仅确证存在的锁定账号，响应头带剩余秒数）

> 防枚举说明：账号不存在、密码错误、锁定与否均返回 10101 统一文案；锁定状态通过 HTTP 423 表达（仅对确证存在的账号）。

### 2.3 POST /auth/sms/send — 发送短信验证码

**鉴权**：无　**限流**：同手机号 60 秒/次 + 10 次/日；IP 30 次/小时　**幂等**：`X-Idempotency-Key`（scene+phone 窗口内去重）

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| country_code | string | 是 | E.164 区号，如 +86 |
| phone | string | 是 | 手机号本体 |
| scene | int | 是 | 整数枚举（与 `sc_verification_codes.scene` 常量一致，03 §3.5）：1=登录注册二合一 / 2=注册（预留）/ 3=重置密码 / 4=绑定手机 / 5=更换邮箱 / 6=KYC L1 |

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": { "ttl": 300 } }
```

> 演示模式（门禁开启，仅 dev/test）：`data` 附 `mock_code`（明文验证码仅演示环境回显，生产关闭返回 403，规范 §1.10）。

**错误码**：10000 / 10105（429，带剩余等待秒）/ 10006

### 2.4 POST /auth/login/sms — 短信验证码登录（注册二合一）

**鉴权**：无　**限流**：账号验证失败 5 次/15 分钟　**幂等**：否（验证码一次性兜底）

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| country_code | string | 是 | E.164 区号 |
| phone | string | 是 | 手机号本体 |
| code | string | 是 | 6 位验证码 |

**响应 200**：同 2.2（`need_totp` 判断）；手机号未注册时**自动建号**（`is_phone_verified=1`、`kyc_level≥1`，即 L1 实名随登录完成），响应 `data.user` 中 `is_new=true`。

**错误码**：10000 / 10102 / 10103 / 10104 / 10006

### 2.5 POST /auth/refresh — 刷新令牌（轮换）

**鉴权**：Refresh Cookie（自动携带）　**限流**：IP 30 次/分钟　**幂等**：否（轮换本身原子化）

无请求体。成功后重种双 Cookie（新 Refresh 起算 30 天）：

```json
{ "code": 0, "message": "ok", "data": { "user": { "id": 1001, "username": "alice" } } }
```

**行为**：
- Refresh 已轮换/吊销 → 401（code 10001）；**若判定重用**（来源设备/IP 与轮换者不一致）→ 吊销该设备全部会话 + 审计（`01-架构设计.md` D1）
- 并发刷新（多标签页）由服务端串行化处理，不触发重用误判
- `token_version` 落后（改密/全部下线后）→ 401

### 2.6 POST /auth/logout — 登出当前设备

**鉴权**：登录态　**限流**：IP 20 次/分钟　**幂等**：条件更新兜底

吊销当前设备全部 refresh token（reason=登出），清 Cookie。

**响应 200**：`{ "code": 0, "message": "ok", "data": null }`

### 2.7 POST /auth/logout-all — 登出全部设备

**鉴权**：登录态　**限流**：IP 10 次/分钟　**幂等**：否（重复调用仅再次自增版本）

吊销全部设备 + `token_version+1`（所有已签发 Access 立即失效）。响应同 2.6。

### 2.8 POST /auth/forgot-password — 发送重置凭证

**鉴权**：无　**限流**：60 秒/次（按账号或手机/邮箱）　**幂等**：`X-Idempotency-Key`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| account | string | 是 | 手机号（含区号）或邮箱；发码渠道按账号类型自动路由（短信/邮件） |

**响应 200**：统一响应（**账号不存在也返回成功**，防枚举）。`data: { ttl: 300 }`

### 2.9 POST /auth/reset-password — 重置密码

**鉴权**：无　**限流**：IP 10 次/分钟　**幂等**：验证码一次性兜底

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| account | string | 是 | 手机号（含区号）或邮箱 |
| code | string | 是 | 收到的验证码 |
| new_password | string | 是 | 8–72 位，须含字母与数字 |

**响应 200**：成功后执行**全局吊销**（token_version+1，全部设备下线），不建立登录态（需重新登录）。

**错误码**：10000 / 10108 / 10102 / 10103 / 10104 / 10006

### 2.10 POST /auth/change-password — 修改密码

**鉴权**：登录态　**限流**：IP 10 次/分钟　**幂等**：否

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| old_password | string | 是 | 原密码（OAuth 建号未设密码用户必须先走 2.9 或绑定手机后设置） |
| new_password | string | 是 | 8–72 位 |

**响应 200**：成功后**全局吊销**（token_version+1）并注销全部设备，需重新登录。

**错误码**：10000 / 10109 / 10001

### 2.11 GET /auth/check — 令牌有效性探测

**鉴权**：登录态（无效返回 401）　**限流**：IP 60 次/分钟

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": { "valid": true, "user": { "id": 1001, "username": "alice" } } }
```

---

## 3. OAuth 模块

### 3.1 GET /oauth/{provider}/authorize — 发起第三方授权

**鉴权**：无（未登录=登录场景）/ 登录态（已登录=绑定场景）　**限流**：IP 20 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| provider | path | 是 | 白名单：`github` / `google`（非白名单 404） |
| redirect_uri | query | 是 | 前端回调地址（白名单校验，防止开放重定向） |

**行为**：服务端生成 `state = random_bytes(32)`（只存哈希，一次性，TTL 5 分钟；绑定场景额外绑定当前 user_id）→ **302** 跳转第三方授权页。

**响应**：302（Location = 第三方授权 URL），不返回 JSON。

### 3.2 GET /oauth/{provider}/callback — 第三方回调

**鉴权**：无　**限流**：IP 20 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| provider | path | 是 | github / google |
| code | query | 是 | 第三方授权码 |
| state | query | 是 | 发起时返回的 state 原文 |

**行为**：验 state 哈希（`hash_equals`）+ 一次性消费（条件更新 `consumed_at`）→ code 换 token（PKCE S256 时带 code_verifier）→ 拉取第三方用户 → 按 `(provider, provider_user_id)` 查绑定：
- 已绑定 → 正常登录（2FA 判断同 2.2）
- 未绑定 + 未登录 → 自动建号（sc_users + sc_user_identities），登录
- 未绑定 + 已登录（绑定场景）→ 建立绑定，返回绑定成功页
- 首次登录响应 `data.user.is_new=true`

**响应**：**302** 到 `redirect_uri`（成功：`?code=0`；失败：`?code=11101` 等，前端按错误码渲染）。

**错误码**（经 302 透传）：10000 / 11101 / 11102

### 3.3 POST /oauth/{provider}/bind — 登录态绑定第三方

**鉴权**：登录态　**限流**：IP 10 次/分钟

**行为**：生成绑定场景 state（绑定当前 user_id）→ 返回授权跳转地址：

```json
{ "code": 0, "message": "ok", "data": { "redirect_url": "https://github.com/login/oauth/authorize?...&state=xxx" } }
```

**错误码**：11103（已绑定该第三方）

### 3.4 DELETE /oauth/{provider}/unbind — 解绑第三方

**鉴权**：登录态　**限流**：IP 10 次/分钟　**幂等**：否

**约束校验**（11104 拒绝，防止账号无法登录）：若用户**无密码（password_hash=''）且无手机且无邮箱**，且该第三方是唯一登录凭证 → 拒绝解绑。

**响应 200**：`{ "code": 0, "message": "ok", "data": null }`

**错误码**：11104 / 10003

---

## 4. 2FA 模块（TOTP）

### 4.1 POST /2fa/enable/start — 生成 TOTP secret

**鉴权**：登录态　**限流**：IP 10 次/分钟

**行为**：生成 32 字节 secret（**不落库**，仅本次响应返回明文）：

```json
{ "code": 0, "message": "ok", "data": {
  "secret": "JBSWY3DPEHPK3PXP",
  "otpauth_uri": "otpauth://totp/App:alice?secret=...&issuer=App",
  "qr_data": "otpauth://totp/..." 
} }
```

**错误码**：12105（已启用，需先 4.3 关闭）

### 4.2 POST /2fa/enable/verify — 校验并启用

**鉴权**：登录态　**限流**：IP 10 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| secret | string | 是 | 4.1 返回的 secret（服务端按本次会话绑定，防串用） |
| code | string | 是 | Authenticator 当前 6 位动态码 |

**行为**：校验通过 → **加密持久化**（AES-256-GCM）→ `totp_enabled=1` → 生成 10 个恢复码：

```json
{ "code": 0, "message": "ok", "data": { "recovery_codes": ["8f3a9c1d", "...×9"] } }
```

> 恢复码**仅本次完整返回**，此后只能单查（4.6）。

**错误码**：10000 / 12101

### 4.3 POST /2fa/disable — 关闭 2FA

**鉴权**：登录态　**限流**：IP 10 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| code | string | 条件 | 当前动态码（与 recovery_code 二选一） |
| recovery_code | string | 条件 | 未使用的恢复码 |

**行为**：验证通过 → `totp_enabled=0`、清除 secret 密文、**全部恢复码作废**（条件更新 `used_at`）→ 触发全局吊销（token_version+1，所有设备重新登录）。

**错误码**：12104 / 12101 / 12103

### 4.4 POST /2fa/login/verify — 登录二次验证

**鉴权**：无（携带 2.2/2.4 返回的 `totp_ticket`）　**限流**：账号 5 次失败/15 分钟（并入失败计数）

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| totp_ticket | string | 是 | 登录首步返回的临时票据 |
| code | string | 是 | 6 位动态码 |

**行为**：验票据 + 验动态码（±1 时间步容差，同一 time_step 防重放）→ 通过后建立完整登录态（种双 Cookie）。失败计入账号失败计数（与密码失败同阈值）。

**响应 200**：同 2.2 成功响应。**错误码**：10001 / 12101 / 12102 / 10007

### 4.5 POST /2fa/recovery/verify — 登录时恢复码验证

**鉴权**：无（携带 `totp_ticket`）　**限流**：账号 5 次失败/15 分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| totp_ticket | string | 是 | 登录首步票据 |
| recovery_code | string | 是 | 未使用的恢复码 |

**行为**：校验（哈希 + 一次性条件更新）→ 登录成功。**错误码**：10001 / 12103

### 4.6 GET /2fa/recovery-codes — 恢复码查询

**鉴权**：登录态　**限流**：IP 10 次/分钟

- 启用后**首次**调用返回完整 10 个；此后仅返回：`{ "total": 10, "remaining": 8 }`（按位单查：`GET /2fa/recovery-codes/{index}` 返回单个明文，限频 60 秒/次）

**错误码**：12104

---

## 5. 设备模块

### 5.1 GET /devices — 设备列表

**鉴权**：登录态　**限流**：IP 20 次/分钟

**响应 200**（游标分页）：

```json
{ "code": 0, "message": "ok", "data": { "items": [
  { "id": 501, "device_name": "Chrome 126 / Windows 11", "last_ip": "1.2.3.4",
    "last_ip_location": "广东省深圳市", "is_current": true, "is_trusted": false,
    "last_active_at": 1755400000 },
  { "id": 502, "device_name": "Safari 17 / macOS", "last_ip": "8.8.8.8",
    "last_ip_location": "北京市", "is_current": false, "is_trusted": true,
    "last_active_at": 1755200000 }
], "next_cursor": null } }
```

### 5.2 DELETE /devices/{id} — 踢设备

**鉴权**：登录态　**限流**：IP 20 次/分钟　**幂等**：条件更新（`revoked_at=0` 防重复踢）

**行为**：吊销设备 + 该设备全部 refresh token（reason=踢设备）+ 审计 `device.kick`。**踢当前设备等同登出**（同步清 Cookie）。

**响应 200**：`{ "code": 0, "message": "ok", "data": null }`　**错误码**：13101 / 13102

### 5.3 PUT /devices/{id}/trust — 设置信任设备

**鉴权**：登录态　**限流**：IP 10 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| trusted | boolean | 是 | true=信任 / false=取消信任 |

**响应 200**：`{ "code": 0, "message": "ok", "data": { "id": 502, "is_trusted": true } }`　**错误码**：13101 / 13102

---

## 6. KYC 实名模块

> 等级单调递增 L0→L1→L2→L3；接口返回的证件号/姓名/手机号一律脱敏（§1.7）。

### 6.1 GET /kyc — 当前实名状态

**鉴权**：登录态　**限流**：IP 30 次/分钟

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": {
  "kyc_level": 2,
  "is_phone_verified": true,
  "latest_record": { "id": 701, "level": 2, "status": 3, "real_name": "张*", "id_card_number": "1101**********1234", "created_at": 1755300000 }
} }
```

### 6.2 POST /kyc/l1 — L1 手机号实名

**鉴权**：登录态　**限流**：同 2.3 短信频控　**幂等**：验证码一次性兜底

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| country_code | string | 是 | 手机区号 |
| phone | string | 是 | 手机号（须为本人已绑定手机号） |
| code | string | 是 | 短信验证码（scene=6） |

**行为**：验证通过 → `is_phone_verified=1`、`kyc_level=max(1, 当前)`、写 sc_kyc_records（level=1, status=3 通过）。**错误码**：10000 / 10102 / 10103 / 10104 / 14101

### 6.3 POST /kyc/l2/submit — 提交三要素

**鉴权**：登录态（kyc_level ≥ 1）　**限流**：IP 10 次/分钟；同日同人 3 次　**幂等**：`X-Idempotency-Key`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| real_name | string | 是 | 真实姓名（2–32 字符） |
| id_card_number | string | 是 | 证件号（按证件类型格式校验） |
| country_code | string | 是 | 手机区号 |
| phone | string | 是 | 手机号（须与实名信息一致，公安校验三要素） |

**响应 200**（异步校验，返回查询凭据）：

```json
{ "code": 0, "message": "ok", "data": { "provider_request_id": "kyc_20260817120000_abc123", "status": 1 } }
```

**错误码**：10000 / 14101（已 L3 或提交中）/ 14102 / 10006

### 6.4 GET /kyc/l2/result — 查询 L2 校验结果（轮询）

**鉴权**：登录态　**限流**：IP 30 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| provider_request_id | string | 是 | 6.3 返回的查询凭据 |

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": {
  "provider_request_id": "kyc_20260817120000_abc123",
  "status": 2, "fail_reason": "",
  "reviewed_at": 1755320000, "kyc_level": 2
} }
```

status：1=提交中 / 2=复核中 / 3=通过 / 4=驳回 / 5=过期；通过后 `kyc_level` 提升。**错误码**：14105

### 6.5 POST /kyc/l3/submit — 发起 L3 人脸活体

**鉴权**：登录态（kyc_level ≥ 2）　**限流**：IP 10 次/分钟；同日同人 3 次

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| provider_session | string | 是 | 前端 SDK 活体采集完成的会话凭证 |

**响应 200**：同 6.3 结构（`provider_request_id` + status）。**错误码**：10000 / 14101 / 14103 / 10006

### 6.6 POST /kyc/callback/{provider} — 第三方回调入口

**鉴权**：无（**第三方签名**验证；验签失败 14104）　**限流**：按 IP 高阈值（信任来源校验优先）

**行为**：验签 → 以 `(provider, provider_request_id)` 唯一约束**幂等落结果**（重复回调不生效、返回 200）→ 更新 sc_kyc_records 状态 → 通过则提升 `kyc_level` → 发布 `KycResultReceived` 事件（通知/审计）。**回调原文存 `callback_raw` 留痕**。

**错误码**：14104 / 14105

### 6.7 GET /kyc/records — 实名记录列表

**鉴权**：登录态　**限流**：IP 20 次/分钟　**分页**：游标（§1.6）

**响应 200**：`data.items[]` 含 level / status / 脱敏姓名与证件号 / created_at / fail_reason。**错误码**：无

---

## 7. 日志模块

### 7.1 GET /logs/login — 我的登录日志

**鉴权**：登录态　**限流**：IP 20 次/分钟　**分页**：游标（§1.6）

**响应 200**：

```json
{ "code": 0, "message": "ok", "data": { "items": [
  { "id": 801, "login_type": 2, "is_success": true, "ip": "1.2.3.4",
    "ip_location": "广东省深圳市", "device_name": "Chrome 126 / Windows 11",
    "is_unusual": false, "created_at": 1755400000 },
  { "id": 800, "login_type": 4, "is_success": false, "fail_reason": 2,
    "ip": "9.9.9.9", "ip_location": "海外", "device_name": "", "is_unusual": false,
    "created_at": 1755390000 }
], "next_cursor": 799 } }
```

### 7.2 GET /logs/audit — 审计日志（预留管理端）

**鉴权**：登录态 + `is_admin=1`　**限流**：IP 10 次/分钟　**分页**：游标

**响应 200**：`data.items[]` 含 action / actor / target / ip / request_id / created_at / detail_json。**错误码**：15101（403）

---

## 8. 用户资料模块

### 8.1 GET /user/me — 当前用户

**鉴权**：登录态　**限流**：IP 30 次/分钟

**响应 200**（全部脱敏）：

```json
{ "code": 0, "message": "ok", "data": {
  "id": 1001, "username": "alice", "nickname": "爱丽丝", "avatar_url": "",
  "phone": "138****1234", "email": "a***@example.com",
  "is_phone_verified": true, "is_email_verified": false,
  "kyc_level": 1, "totp_enabled": true, "created_at": 1755000000
} }
```

### 8.2 PUT /user/me — 修改资料

**鉴权**：登录态　**限流**：IP 10 次/分钟

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| nickname | string | 否 | 1–32 字符 |
| avatar_url | string | 否 | 头像地址（白名单域名校验） |

**响应 200**：更新后的脱敏用户信息。**错误码**：10000

### 8.3 PUT /user/me/phone — 绑定/更换手机号

**鉴权**：登录态　**限流**：短信频控（§2.3）　**幂等**：验证码一次性兜底

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| country_code | string | 是 | 新手机区号 |
| phone | string | 是 | 新手机号 |
| code | string | 是 | 发送至新手机号的验证码（scene=4） |

**错误码**：10000 / 16102（该手机号已绑定其他账号，409）/ 10102 / 10103 / 10104

### 8.4 PUT /user/me/email — 绑定/更换邮箱

**鉴权**：登录态　**限流**：60 秒/次　**幂等**：验证码一次性兜底

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| email | string | 是 | 新邮箱 |
| code | string | 是 | 发送至新邮箱的验证码（scene=5） |

**错误码**：10000 / 16103（409）/ 10102 / 10103 / 10104

---

## 9. 通知模块

### 9.1 GET /notifications — 通知列表

**鉴权**：登录态　**限流**：IP 20 次/分钟　**分页**：游标

**响应 200**：`data.items[]` 含 id / scene / channel / title / status（1=待发/2=已发/3=失败/4=死信，03 §3.10）/ sent_at / created_at。**content 不返回**（详情走 9.2）。

### 9.2 GET /notifications/{id} — 通知详情

**鉴权**：登录态（仅本人）　**限流**：IP 30 次/分钟

**响应 200**：`data` 含标题、脱敏收件地址、内容、发送状态与时间。**错误码**：17101

---

## 10. 附录：关键场景时序

### 10.1 完整登录（含 2FA）

```
浏览器                后端
  │ POST /auth/login   │
  ├───────────────────▶│ 查号 → 验密码 → 失败计数/锁定 → 设备归并 → 异地判定
  │◀───────────────────┤ { need_totp: true, totp_ticket }
  │ POST /2fa/login/verify │
  ├───────────────────▶│ 验票据 + 验动态码 → 发双 Cookie → sc_login_logs → 事件
  │◀───────────────────┤ { user }
```

### 10.2 OAuth 登录

```
浏览器           后端             GitHub/Google
  │ GET /oauth/github/authorize │
  ├──────────────▶│ 生成 state（哈希入库）→ 302 第三方授权页
  │◀──────────────┤
  │──── 302 ────────────────────▶│ 用户授权
  │◀──── 302 + code ────────────┤
  │ GET /oauth/github/callback?code&state │
  ├──────────────▶│ 验 state（一次性）→ code 换 token → 拉用户
  │               ├──── code/token 交换 ──▶│
  │               │◀── 用户信息 ───────────┤
  │               │ 查绑定 → 无则建号 → 登录 → 种 Cookie
  │◀─ 302 redirect?code=0 ────┤
```

### 10.3 令牌刷新与重用检测

```
  POST /auth/refresh
  ├─ Refresh Cookie 存在 & 未轮换 → 签发新对（旧行 rotated_at 条件更新）
  ├─ Refresh 已轮换 & 同设备/IP → 视为并发重试，返回最新会话
  ├─ Refresh 已轮换 & 异设备/IP → 重用检测：吊销设备全部会话 + 审计 + 401
  └─ token_version 落后 → 401（改密/全部下线场景）
```

### 10.4 KYC L2 提交-回调

```
前端                后端                   第三方（三要素）
  │ POST /kyc/l2/submit │
  ├───────────────────▶│ 校验格式 → 建 sc_kyc_records(submitting)
  │                    │ 入队 KycVerifyTask（事务外）──▶│ 校验
  │◀── { provider_request_id } ──┤                      │
  │                    │◀── 回调 POST /kyc/callback（验签+幂等）──┤
  │                    │ 更新记录 → 通过则 kyc_level=2 → 事件
  │ GET /kyc/l2/result │（轮询兜底：第三方无回调时）
  ├───────────────────▶│ status=3 → kyc_level=2
```

### 10.5 踢设备

```
前端                    后端
  │ DELETE /devices/502 │
  ├────────────────────▶│ 条件更新设备 revoked_at（防重复踢）
  │                     │ 吊销该设备全部 refresh（reason=踢设备）
  │                     │ 审计 device.kick
  │◀──────── 200 ───────┤
（被踢设备下一次 refresh → 401 → 前端清 Cookie → 登录页）
```

---

*错误码与状态码对照 `设计规范.md` §1.6；字段与表对应 `03-数据库设计.md`；限流矩阵与安全细节见 `04-安全设计.md`。*
