# 生产级登录注册系统

基于 **Vue 3 + Element Plus + Hyperf (PHP/Swoole) + MySQL 8 + Redis** 的生产级登录注册系统，提供多种登录方式、设备管理、KYC 实名认证、2FA、防暴力破解与异地登录告警，Docker Compose 一键启动，日志体系与 Kubernetes 同构，可平滑迁移 K8s。

## 功能总览

| 模块 | 能力 |
|---|---|
| 登录方式（5 种） | 账号+密码 · 手机号+短信验证码（登录注册二合一）· 邮箱+密码 · GitHub OAuth2 · Google OAuth2 |
| 安全 | JWT 双令牌（Access 15 分钟 + Refresh 30 天轮换可吊销）、密码 bcrypt(cost 12)、Google Authenticator TOTP 2FA + 恢复码、防暴力破解（分层限流 + 账号锁定 423）、CSRF 双保险、OAuth state 一次性 + PKCE |
| 设备管理 | 设备列表、信任设备、一键踢设备（含被踢设备会话即时吊销） |
| KYC 实名 | L0→L1（手机号）→L2（证件三要素，第三方校验）→L3（人脸活体，第三方）；异步回调 + 轮询双通道，回调幂等 |
| 日志审计 | 登录日志（含异地判定标记）、审计日志（只追加双保障）；request_id 全链路 |
| 告警 | 异地登录告警（仅邮件，自动降噪 + 基线学习） |
| 部署 | Docker Compose 一键启动；stdout JSON 日志 + Promtail/Loki/Grafana；K8s 演进方案 |

> 当前仓库处于**实现阶段**：`设计规范.md` 为强制约束（评审基线），`docs/` 为唯一权威文档，`backend/`（已实现 39+ 接口）、`frontend/`、`deploy/`、`sql/` 为代码产出。

## 快速开始

### 环境要求

- Docker（含 Compose v2）+ Docker Desktop（Windows/macOS）或 Linux Docker Engine
- 可联网拉取镜像（首次约 5–10 分钟）

### 一键启动（dev，含全部服务与日志栈）

```bash
cd deploy
cp .env.example .env   # 填入 JWT_SECRET / JWT_TICKET_SECRET / TOTP_ENCRYPTION_KEY（必填）
docker compose up -d --build
```

仅启动核心服务（不含日志栈，更快）：

```bash
docker compose up -d --build mysql redis backend frontend
```

### 访问

| 入口 | 地址 |
|---|---|
| 前端 | http://localhost:8080 |
| Grafana（日志/监控，full profile） | http://localhost:3000（admin / admin，首次改密） |
| sms-mock 回显（full profile） | http://localhost:9502 |

### 演示账号

| 账号 | 密码 | 说明 |
|---|---|---|
| `demo` | `Demo@123456` | 已创建（seed 自动初始化，is_admin=1 可查审计日志） |

> **dev 环境短信验证码**：验证码回显在发码接口响应 `data.mock_code` 与后端日志（SMS_MOCK 门禁，仅 dev/test）。生产环境该能力被门禁强制关闭（403，见 `docs/04-安全设计.md` §4.3）。

### 常用命令

```bash
docker compose logs -f backend        # 后端日志（JSON）
docker compose exec backend php bin/hyperf.php migrate   # 手动执行迁移（幂等）
docker compose exec backend php bin/hyperf.php db:seed   # 演示数据（幂等）
docker compose down                   # 停止（保留数据卷）
docker compose down -v                # 停止并清空数据（谨慎）
```

### 本地开发（无 Docker）

- 后端：PHP ≥ 8.2 + Swoole 扩展；`cd backend && composer install && php bin/hyperf.php start`
- 前端：Node ≥ 20；`cd frontend && npm install && npm run dev`（Vite 代理 `/api` → 9501）

## 目录结构

```
mem_reg/
├── 设计规范.md          # 强制设计规范（[必须] 项为评审红线）
├── CLAUDE.md            # 开发助手指引（规范速查）
├── README.md            # 本文档
├── docs/
│   ├── 01-架构设计.md   # 总体架构、认证子系统、D1–D9 关键决策
│   ├── 02-API文档.md    # 39 个接口契约、错误码、时序附录
│   ├── 03-数据库设计.md # 12 张表 DDL、索引策略、归档、自检清单
│   ├── 04-安全设计.md   # 威胁模型、凭证/会话安全、防爆破矩阵、审计合规
│   └── 05-部署运维.md   # Compose、环境变量、日志栈、备份、K8s 演进
├── sql/schema.sql       # 基线 Schema（sc_ 前缀，幂等，已本地双路径验证）
├── backend/             # Hyperf 3.x 后端（Controller→Service→Model）
│   ├── app/             # Controller / Service / Model / Middleware / Provider / Job…
│   ├── migrations/      # 幂等迁移（migrate 命令执行）
│   └── .env.example     # 环境变量模板（docs/05 §3）
├── frontend/            # Vue 3 + Vite + Element Plus + Pinia
│   └── src/             # api / stores / router / views / utils / constants
└── deploy/              # compose、Dockerfile、nginx、日志栈、备份脚本
```

## 文档导航

| 想了解 | 阅读 |
|---|---|
| 设计规范与评审红线 | `设计规范.md`（`**[必须]**` 项） |
| 系统怎么拼起来的、关键决策 | `docs/01-架构设计.md`（§10 D1–D9） |
| 接口怎么调、错误码 | `docs/02-API文档.md` |
| 表结构与字段口径 | `docs/03-数据库设计.md` |
| 安全怎么保证、发布检查清单 | `docs/04-安全设计.md` |
| 怎么部署、日志监控、上 K8s | `docs/05-部署运维.md` |

## 文档与实现的关系

- 六份文档为唯一权威出处：接口路径、错误码、表名/字段、限流阈值、环境变量名以文档为准，实现不得偏离
- 实现约定：全表统一 `sc_` 前缀（`sql/schema.sql` 与 `backend/migrations/` 已同步；docs/03 表名引用已同步）
- `设计规范.md` §3 评审清单与 `docs/03` §7 自检表一一对应，新功能落库前先过清单
- 里程碑：① Compose 跑通注册/登录/刷新（无 2FA/KYC）→ ② 设备与安全加固（2FA/防爆破/异地告警）→ ③ KYC 与 OAuth 第三方接入 → ④ 日志栈与 K8s

## 技术栈与版本基线

| 组件 | 版本 | 备注 |
|---|---|---|
| 前端 | Vue 3 + Vite + Element Plus + Pinia + axios | 401 静默刷新（`docs/01` §7.2） |
| 后端 | Hyperf 3.x（PHP 8.2 / Swoole） | 三层架构 Controller→Service→Model |
| 数据库 | MySQL 8.0（utf8mb4） | 12 张表（sc_ 前缀），DDL 见 `docs/03` |
| 缓存/队列 | Redis 7 | 限流 + async-queue（可降级 DB） |
| 部署 | Docker Compose → K8s | 日志 stdout JSON 同构 |
