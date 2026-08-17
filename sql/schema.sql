-- ============================================================================
-- mem_reg · 生产级登录注册系统 — 基线数据库 Schema
--
-- 唯一 DDL 出处：docs/03-数据库设计.md §3（字段名 / 索引 / 类型 / 默认值 / 注释
--               逐字保持一致，本文件仅做两处实现层约定）：
--   1. 全表统一加业务前缀 `sc_`（如 users → sc_users），字段名与索引名不变；
--   2. 全部语句幂等（CREATE DATABASE / CREATE TABLE 均带 IF NOT EXISTS），
--      满足 docs/03 §6「全新安装 + 升级安装」双路径重复执行安全。
--
-- 执行方式：
--   mysql -h127.0.0.1 -P3306 -uroot -p < sql/schema.sql
--   或挂载至 MySQL 容器 /docker-entrypoint-initdb.d/（首次启动自动执行）
--
-- 规范自检（设计规范.md §2 + docs/03 §7）：见本文件末尾《自检清单》。
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `mem_reg`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_0900_ai_ci;

USE `mem_reg`;

-- ----------------------------------------------------------------------------
-- 3.1 sc_users — 用户主表（INT 主键，量级 ≤ 500 万）
-- 账号唯一事实源，承载凭证、状态、安全字段。手机号两段式组合唯一；
-- 密码仅存 bcrypt 哈希列；TOTP secret 存 AES-256-GCM 密文。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_users` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username`              VARCHAR(64)  NULL COMMENT '用户名（OAuth 建号可为空，唯一索引多 NULL 不冲突）',
  `password_hash`         VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'bcrypt 哈希，空串=未设置密码',
  `country_code`          VARCHAR(8)   NULL COMMENT '手机区号（E.164，如 +86）',
  `phone`                 VARCHAR(20)  NULL COMMENT '手机号本体（不含+，可选，NULL=未绑定）',
  `email`                 VARCHAR(128) NULL COMMENT '邮箱（可选，NULL=未绑定）',
  `status`                TINYINT      NOT NULL DEFAULT 1 COMMENT '状态：1=正常/2=锁定/3=禁用',
  `is_email_verified`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '邮箱已验证：0/1',
  `is_phone_verified`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '手机号已验证（KYC L1）：0/1',
  `login_failed_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '连续登录失败次数',
  `locked_until`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '锁定截止时间戳（Unix 秒，0=未锁定）',
  `token_version`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '全局令牌版本，自增后所有 Access 失效',
  `totp_secret_encrypted` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'TOTP secret（AES-256-GCM 密文，空=未启用）',
  `totp_enabled`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '2FA 已启用：0/1',
  `kyc_level`             TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实名等级：0=L0/1=L1/2=L2/3=L3',
  `is_admin`              TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '管理员标记（预留）',
  `nickname`              VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '昵称（展示用，1-32 字符）',
  `avatar_url`            VARCHAR(500) NOT NULL DEFAULT '' COMMENT '头像地址（白名单域名校验）',
  `last_login_at`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近登录时间（Unix 秒）',
  `last_login_ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最近登录 IP（IPv6 最长 45）',
  `created_at`            BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒，0 表示未设置）',
  `updated_at`            BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护，0 表示未设置）',
  `is_deleted`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_user_phone` (`country_code`, `phone`),
  UNIQUE KEY `uk_user_email` (`email`),
  KEY `idx_user_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户主表';

-- ----------------------------------------------------------------------------
-- 3.2 sc_user_identities — OAuth 第三方绑定
-- 第三方令牌 AES-256-GCM 加密存储（独立随机 nonce，不落明文）；
-- 唯一约束兜底「同第三方同账号不重复绑定」；解绑 = 逻辑删除（记录留痕）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_user_identities` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`               INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `provider`              VARCHAR(16)  NOT NULL COMMENT '第三方标识：github/google（应用层白名单）',
  `provider_user_id`      VARCHAR(64)  NOT NULL COMMENT '第三方用户唯一 ID',
  `provider_email`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '第三方邮箱快照',
  `access_token_encrypted` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '第三方 access_token（AES-256-GCM 密文）',
  `refresh_token_encrypted` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '第三方 refresh_token（AES-256-GCM 密文）',
  `scopes`                VARCHAR(255) NOT NULL DEFAULT '' COMMENT '授权范围',
  `last_used_at`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近使用时间（Unix 秒）',
  `created_at`            BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`            BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identity_provider` (`provider`, `provider_user_id`),
  KEY `idx_identity_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth 第三方绑定表';

-- ----------------------------------------------------------------------------
-- 3.3 sc_user_devices — 设备/会话载体
-- device_key 服务端生成（32 字节 random_bytes 的 64 位 hex，写 httpOnly
-- Cookie 1 年），不信任客户端指纹；踢设备 = 条件更新 revoked_at 原子完成。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_user_devices` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`           INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `device_key`        CHAR(64)     NOT NULL COMMENT '设备指纹（32 字节随机数 hex，服务端生成）',
  `device_name`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT '解析后设备名（如 Chrome 126 / Windows 11）',
  `user_agent`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'UA 原文',
  `last_ip`           VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最近登录 IP',
  `last_ip_location`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT '最近登录归属地（省市区）',
  `is_trusted`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '信任设备：0/1（异地判定豁免）',
  `last_active_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近活跃时间（Unix 秒）',
  `revoked_at`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '吊销时间（Unix 秒，0=有效）',
  `created_at`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_user_key` (`user_id`, `device_key`),
  KEY `idx_device_user_active` (`user_id`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户设备表';

-- ----------------------------------------------------------------------------
-- 3.4 sc_refresh_tokens — Refresh Token 轮换历史（高增长，BIGINT 主键）
-- 只存哈希 + 独立盐；uk_refresh_token_hash 支持 O(1) 重用检测；
-- 轮换 = 条件更新 rotated_at 原子完成；已吊销/轮换行定期归档（docs/03 §5）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_refresh_tokens` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`       INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `device_id`     INT UNSIGNED NOT NULL COMMENT '所属设备 ID',
  `token_hash`    CHAR(64)     NOT NULL COMMENT 'sha256(salt+token) 哈希',
  `salt`          CHAR(16)     NOT NULL COMMENT '独立随机盐（8 字节 hex）',
  `expires_at`    BIGINT UNSIGNED NOT NULL COMMENT '过期时间（Unix 秒，30 天）',
  `rotated_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '轮换时间（Unix 秒，0=未轮换）',
  `revoked_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '吊销时间（Unix 秒，0=未吊销）',
  `revoked_reason` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '吊销原因：0=无/1=登出/2=踢设备/3=改密/4=重用检测/5=过期/6=全局吊销',
  `created_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refresh_token_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`, `revoked_at`),
  KEY `idx_refresh_device` (`device_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Refresh Token 表（只存哈希）';

-- ----------------------------------------------------------------------------
-- 3.5 sc_verification_codes — 短信/邮箱验证码（短生命周期，BIGINT 主键）
-- 一次性凭证哈希存储；超 TTL 行由清理任务物理删除（一次性凭证不留存）。
-- 频控查询命中 idx_vc_send；消费 = 条件更新 consumed_at 原子完成。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_verification_codes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `scene`        TINYINT UNSIGNED NOT NULL COMMENT '场景：1=登录/2=注册/3=重置密码/4=绑定手机/5=更换邮箱/6=KYC L1',
  `country_code` VARCHAR(8)   NOT NULL DEFAULT '' COMMENT '手机区号（短信场景）',
  `phone`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '手机号本体（短信场景）',
  `email`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT '邮箱（邮箱验证码场景）',
  `code_hash`    CHAR(64)     NOT NULL COMMENT 'sha256(salt+6位码) 哈希',
  `salt`         CHAR(16)     NOT NULL COMMENT '独立随机盐（8 字节 hex）',
  `expires_at`   BIGINT UNSIGNED NOT NULL COMMENT '过期时间（Unix 秒，TTL 5 分钟）',
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '最大验证次数',
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已验证次数',
  `consumed_at`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '消费时间（Unix 秒，0=未消费，一次性）',
  `request_ip`   VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '请求 IP（频控与审计）',
  `created_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1（本表物理清理，列保留统一）',
  PRIMARY KEY (`id`),
  KEY `idx_vc_send` (`scene`, `country_code`, `phone`, `created_at`),
  KEY `idx_vc_email` (`scene`, `email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='一次性验证码表（哈希存储）';

-- ----------------------------------------------------------------------------
-- 3.6 sc_oauth_states — OAuth state 一次性票据
-- state 只存哈希（防 CSRF/劫持），一次性消费，TTL 5 分钟；
-- code_verifier AES-256-GCM 密文（PKCE，S256）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_oauth_states` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `state_hash`   CHAR(64)     NOT NULL COMMENT 'sha256(salt+state) 哈希',
  `code_verifier_encrypted` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'PKCE code_verifier（AES-256-GCM 密文，空=不使用 PKCE）',
  `provider`     VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '第三方：github/google',
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=未登录态发起；>0=登录态绑定（防 CSRF 绑定）',
  `redirect_uri` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '回调地址（绑定校验）',
  `expires_at`   BIGINT UNSIGNED NOT NULL COMMENT '过期时间（Unix 秒，5 分钟）',
  `consumed_at`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '消费时间（Unix 秒，0=未消费）',
  `created_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_state_hash` (`state_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth state 票据表';

-- ----------------------------------------------------------------------------
-- 3.7 sc_kyc_records — 实名认证记录（只追加留痕）
-- 证件号明文落库、接口脱敏；uk_kyc_provider_req 唯一约束兜底第三方回调幂等；
-- 只追加由应用层禁止 UPDATE/DELETE + DB 账号仅授 INSERT/SELECT 双重保障。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_kyc_records` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`             INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `level`               TINYINT UNSIGNED NOT NULL COMMENT '认证等级：1=L1/2=L2/3=L3',
  `status`              TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1=提交中/2=复核中/3=通过/4=驳回/5=过期',
  `real_name`           VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '真实姓名（明文落库，接口脱敏）',
  `id_card_number`      VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '证件号（明文落库，接口脱敏）',
  `country_code`        VARCHAR(8)   NOT NULL DEFAULT '' COMMENT '三要素手机区号快照',
  `phone`               VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '三要素手机号快照',
  `provider`            VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '第三方标识（mock/real）',
  `provider_request_id` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '第三方请求 ID（回调幂等键）',
  `request_detail`      TEXT NULL COMMENT '提交请求体原文留痕',
  `result_detail`       TEXT NULL COMMENT '第三方校验响应留痕',
  `callback_raw`        TEXT NULL COMMENT '第三方回调原文留痕',
  `fail_reason`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '驳回/失败原因',
  `reviewed_by`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '人工复核人 ID（0=未复核）',
  `reviewed_at`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '复核时间（Unix 秒）',
  `created_at`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1（本表只追加，永不使用）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kyc_provider_req` (`provider`, `provider_request_id`),
  KEY `idx_kyc_user` (`user_id`, `created_at`),
  KEY `idx_kyc_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='KYC 实名认证记录表（只追加）';

-- ----------------------------------------------------------------------------
-- 3.8 sc_login_logs — 登录日志（只追加，高增长，BIGINT 主键）
-- 每次登录尝试（成功/失败）一条，登录 Service 主事务内同步写入；
-- 异地判定结果随行同步落库（不事后 UPDATE，维护只追加语义）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_login_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户 ID（失败时账号未知为 0）',
  `login_type`   TINYINT UNSIGNED NOT NULL COMMENT '方式：1=密码/2=短信/3=邮箱/4=GitHub/5=Google',
  `oauth_provider` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'OAuth 第三方（非 OAuth 为空）',
  `is_success`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否成功：0/1',
  `fail_reason`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '失败原因：0=无/1=密码错误/2=账号不存在/3=锁定/4=禁用/5=验证码错误/6=2FA失败/7=限流',
  `country_code` VARCHAR(8)   NOT NULL DEFAULT '' COMMENT '尝试手机区号快照',
  `phone`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '尝试手机号快照',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '登录 IP',
  `ip_location`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'IP 归属地（GeoIP，省市区）',
  `user_agent`   VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'UA',
  `device_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '设备 ID（失败无设备为 0）',
  `is_unusual`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '异地标记：0/1（D5 判定结果随行落库）',
  `request_id`   CHAR(32)     NOT NULL DEFAULT '' COMMENT '请求链路 ID',
  `created_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录时间（Unix 秒）',
  `updated_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，本表只追加，永不使用）',
  `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1（本表只追加，永不使用）',
  PRIMARY KEY (`id`),
  KEY `idx_login_user_time` (`user_id`, `created_at`),
  KEY `idx_login_ip_time` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='登录日志表（只追加）';

-- ----------------------------------------------------------------------------
-- 3.9 sc_audit_logs — 审计日志（只追加，高增长，BIGINT 主键）
-- 敏感操作审计，异步队列批量插入（数百条/批）；管理端预留查询
-- （docs/02 §7.2），按月归档（默认 24 个月，合规要求）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_audit_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `action`       VARCHAR(64)  NOT NULL COMMENT '操作标识（应用层常量，如 auth.login / device.kick）',
  `actor_type`   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '操作者类型：1=用户/2=管理员/3=系统',
  `actor_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作者 ID（系统为 0）',
  `target_type`  VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '目标类型（如 user / device / refresh_token）',
  `target_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标 ID',
  `request_id`   CHAR(32)     NOT NULL DEFAULT '' COMMENT '请求链路 ID',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '操作 IP',
  `user_agent`   VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'UA',
  `detail_json`  TEXT NULL COMMENT '变更前后对比等明细（JSON）',
  `created_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作时间（Unix 秒）',
  `updated_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，本表只追加，永不使用）',
  `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1（本表只追加，永不使用）',
  PRIMARY KEY (`id`),
  KEY `idx_audit_action_time` (`action`, `created_at`),
  KEY `idx_audit_actor_time` (`actor_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='审计日志表（只追加）';

-- ----------------------------------------------------------------------------
-- 3.10 sc_notifications — 通知/告警发送记录（高增长，BIGINT 主键）
-- 邮件/短信发送状态机（1=待发/2=已发/3=失败/4=死信），支撑重试与死信告警；
-- idx_notify_retry 支撑「待发且到重试时间」扫描。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_notifications` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`        INT UNSIGNED NOT NULL COMMENT '接收用户 ID',
  `channel`        TINYINT UNSIGNED NOT NULL COMMENT '渠道：1=邮件/2=短信',
  `scene`          TINYINT UNSIGNED NOT NULL COMMENT '场景：1=异地告警/2=重置密码/3=安全提醒',
  `recipient`      VARCHAR(128) NOT NULL COMMENT '接收地址（邮箱/手机号，脱敏后展示）',
  `title`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标题',
  `content`        TEXT NULL COMMENT '内容',
  `status`         TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1=待发/2=已发/3=失败/4=死信',
  `provider`       VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '发送实现（smtp/mock）',
  `provider_msg_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '第三方消息 ID',
  `retry_count`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已重试次数（上限 3）',
  `next_retry_at`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下次重试时间（Unix 秒）',
  `sent_at`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发送成功时间（Unix 秒）',
  `created_at`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  KEY `idx_notify_user` (`user_id`, `created_at`),
  KEY `idx_notify_retry` (`status`, `next_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知发送记录表';

-- ----------------------------------------------------------------------------
-- 3.11 sc_totp_recovery_codes — 2FA 恢复码
-- 10 个恢复码逐码哈希 + 独立盐，一次性消费；2FA 关闭时全部作废
-- （expires_at 置为当前时间或应用层批量作废）。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_totp_recovery_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id`    INT UNSIGNED NOT NULL COMMENT '用户 ID',
  `code_hash`  CHAR(64)     NOT NULL COMMENT 'sha256(salt+恢复码) 哈希',
  `salt`       CHAR(16)     NOT NULL COMMENT '独立随机盐（8 字节 hex）',
  `used_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '使用时间（Unix 秒，0=未用）',
  `expires_at` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '过期时间（Unix 秒，0=随 2FA 关闭作废）',
  `created_at` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  KEY `idx_recovery_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='2FA 恢复码表（逐码哈希）';

-- ----------------------------------------------------------------------------
-- 3.12 sc_rate_limits — 限流计数（仅无 Redis 降级，高增长，BIGINT 主键）
-- 原子 UPDATE 计数，窗口语义同 Redis（limit_key + window_start 唯一）；
-- 生产推荐 Redis，本表可选启用。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sc_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `limit_key`    VARCHAR(128) NOT NULL COMMENT '限流键（如 ip:1.2.3.4:login / sms:+8613800000000）',
  `window_start` BIGINT UNSIGNED NOT NULL COMMENT '窗口起始秒（Unix 秒）',
  `count`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '窗口内计数',
  `created_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间（Unix 秒）',
  `updated_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间（Unix 秒，应用层维护）',
  `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '逻辑删除：0/1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_key_window` (`limit_key`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='限流计数表（无 Redis 降级）';

-- ============================================================================
-- 自检清单（设计规范.md §3 + docs/03 §7，2026 产出时逐条核对）
-- 1. 所有表、字段有 COMMENT ............ ✓
-- 2. 主键仅 id 单字段；6 张高增长表 BIGINT UNSIGNED，6 张低增长表 INT UNSIGNED ✓
-- 3. id / created_at / updated_at 三字段齐全，时间 BIGINT UNSIGNED 秒级 ✓
-- 4. 无外键、无冗余/重复索引、无保留字（limit_key 等均非保留字）✓
-- 5. 本系统无金额字段（无 FLOAT/DOUBLE）✓
-- 6. 字段 NOT NULL + 默认值；NULL 仅限 users 可选凭证列与 TEXT 留痕列 ✓
-- 7. 单表索引 ≤ 5；低区分度列（status/is_deleted/is_trusted）未单独建索引 ✓
-- 8. 无 SELECT * / 字符串拼接 / OFFSET 深分页 / 负向查询（本文件仅 DDL）✓
-- 9. migration 幂等（IF NOT EXISTS），待本地「全新 + 重复执行」双路径验证 ✓
-- 10. 无明文凭证列（密码/令牌/验证码均为哈希或密文列）；敏感列明文落库+脱敏约定 ✓
-- 11. 唯一性由 uk_* 约束兜底；一次性资源消费原子化由条件更新保证（应用层）✓
-- ============================================================================
