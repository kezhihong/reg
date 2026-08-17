# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目状态

- **设计阶段已定稿**：`设计规范.md` + 6 份开发文档（`README.md` + `docs/01~05`）为唯一权威出处，仓库内尚无代码（仅有 `.idea/` PHPStorm 配置）。
- 技术栈已确认：Vue 3 + Element Plus / Hyperf (PHP/Swoole) / MySQL 8 / Redis / Docker Compose → K8s（见 `README.md`）。
- 实现任何功能前，先阅读对应文档章节，再按 `设计规范.md` 第 3 节清单自检；接口路径、错误码、表名/字段、限流阈值、环境变量名以文档为准，不得偏离。

## 开发文档导航（唯一权威出处）

| 文档 | 内容 | 实现前必读 |
|---|---|---|
| `README.md` | 快速开始、演示账号、目录结构、里程碑 | — |
| `docs/01-架构设计.md` | 总体架构、认证子系统、异步队列、D1–D9 关键决策（§10） | 全篇 |
| `docs/02-API文档.md` | 39 个接口契约、错误码（10xxx~17xxx）、限流、时序附录 | 对应模块接口 |
| `docs/03-数据库设计.md` | 12 张表 DDL、索引策略、归档、migration 流程（§6）、自检表（§7） | 建表/改表前 |
| `docs/04-安全设计.md` | 威胁模型、凭证/会话安全、防爆破矩阵、审计只追加、发布检查清单（§9） | 安全相关功能 |
| `docs/05-部署运维.md` | Compose、.env 变量表、日志栈、备份、K8s 演进 | 部署前 |

交叉引用约定：接口 ↔ 错误码 ↔ 表字段 ↔ 限流阈值必须保持文档间一致，改动一处需同步全部引用点。

## 权威规范：`设计规范.md`

`设计规范.md` 是本文档**唯一且强制性**的规范来源，涉及建表、SQL、migration 与 PHP 编码时**必须**完整阅读并逐条自检，本文档只是速查。规范级别约定：

- **[必须]** 强制要求，不满足即评审不通过
- **[应该]** 推荐做法，默认遵循
- **[建议]** 可选优化

## 技术栈与语言约束

- PHP ≥ 8.1，`declare(strict_types=1);` 置于每个文件首行；Composer 管理依赖并提交 `composer.lock`；PSR-12 + PSR-4；方法必须有参数与返回类型声明。
- MySQL（InnoDB，`utf8mb4`，MySQL 8 用 `utf8mb4_0900_ai_ci`），本规范不适用于 PostgreSQL / MongoDB / Redis 场景。
- 分层：`Controller → Service → Model`，依赖单向、不得跨层。Controller 只做「取参 → 校验 → 调用 → 响应」，禁止 SQL、循环、复杂分支；Service 管业务规则与事务；Model 管表映射。
- 依赖构造函数注入；业务异常统一继承自定义异常基类（携带错误码），禁止裸 `\Exception`。

## 易踩坑的强制规则（与常见默认做法不同，务必注意）

### MySQL 表设计

- **时间一律用 `BIGINT UNSIGNED` 正整数 Unix 时间戳（UTC）**：默认秒级；`created_at` / `updated_at` 固定秒级，其余时间字段业务要求毫秒精度时可用毫秒级（列名不加后缀）；禁止 `DATETIME` / `TIMESTAMP` / `DATE` / 字符串（纯日期场景如生日同样用时间戳）；展示格式化在应用层。每表必备 `id`、`created_at`、`updated_at` 三字段，命名全库统一。
- 主键默认 `id INT UNSIGNED AUTO_INCREMENT`（预计行数接近 21 亿的高增长表如 message 用 `BIGINT UNSIGNED`），**禁止 UUID / 字符串 / 业务唯一键做主键**；业务唯一性用唯一索引表达。
- **禁止外键与级联**；禁止存储过程 / 触发器 / 视图承载业务逻辑。
- 所有字段 `NOT NULL` + 默认值；所有表、字段必须有 `COMMENT`。
- 逻辑删除统一 `is_deleted TINYINT(1) NOT NULL DEFAULT 0`（全库唯一口径）。
- 金额用 `DECIMAL(M,2)`，**禁止 `FLOAT` / `DOUBLE`**；布尔用 `TINYINT(1)`，状态 / 枚举用 `TINYINT` / `INT`（按取值范围，不设上限，配应用层常量，**禁止 ENUM 与英文枚举值**）；不定长字符串 `VARCHAR(N)`，禁止一律 `VARCHAR(255)`。
- **手机号两段式**：`country_code VARCHAR(8)`（E.164，如 `+86`）+ `phone VARCHAR(20)`（不含 `+`），唯一约束建在 `(country_code, phone)` 组合上。
- 密码 / 令牌 / 验证码等凭证绝不落明文：一次性凭证只存哈希（加盐），比较用 `hash_equals`；敏感个人信息（证件号 / 银行卡号 / 手机号）统一**明文落库**、接口只返回脱敏值。

### SQL 编写

- **禁止 `SELECT *`**（必须明确列名）；INSERT 必须指定字段。
- 深分页禁止 `LIMIT offset, size`，用游标分页（`WHERE id < ?`）；时间范围用半开区间 `>= AND <`，禁止 `BETWEEN` / `<=`。
- 索引列上禁止函数运算与隐式类型转换；禁止负向查询（`!=` / `NOT IN` / `NOT LIKE`）与 `%xxx` 前缀模糊查询；禁止 `ORDER BY RAND()`。
- 一律参数绑定 / ORM，禁止字符串拼接 SQL；`EXPLAIN` 验证关键查询（`type` 应为 `const` / `ref` / `range`，禁止 `ALL`）。
- 并发：读改写必须原子化（`SET x = x + 1` 或条件更新），禁止「先查后插」保证唯一性；一次性资源消费必须原子，防止并发重复消费。

### 其他

- 安全：密码 bcrypt（cost ≥ 12）或 argon2；登录失败统一文案防用户枚举；写操作防 CSRF；令牌存 httpOnly Cookie；上传文件扩展名 + MIME 白名单 + 魔数校验。
- 配置：分层（dev / test / prod），敏感配置只来自环境变量（`.env.example` 模板），生产关闭调试开关（演示功能要有运行时门禁，关闭返回 403/404）。
- 性能：查询防 N+1（预加载）；分页必须有 `per_page` 上限；禁止循环内查库 / 发远程请求；批量插入避免逐条 insert。

## 评审入口

提交评审前按 `设计规范.md` 第 3 节的「提交前检查清单」逐条核对（含「禁止做的事」红线）。migration 必须幂等（`IF NOT EXISTS` / `INSERT IGNORE`），并本地验证「全新安装 + 升级安装」两条路径。

## 命令

当前尚无代码，未配置任何构建 / lint / 测试命令。规范要求的基线（实现后需落地）：

- 静态检查：phpstan / psalm 纳入 CI（`[应该]` 项）
- 测试：认证、支付、权限等关键流程必须有自动化测试（`[必须]` 项）；修复 bug 先补回归测试
- 开发环境：Composer 基于 `composer.lock` 构建
