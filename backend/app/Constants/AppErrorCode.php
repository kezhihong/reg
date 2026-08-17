<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * 业务错误码总表（docs/02-API文档.md §1.3，唯一权威出处）。
 * HTTP 状态码语义遵循 设计规范.md §1.6 / docs/02 §1.2。
 */
final class AppErrorCode
{
    // ---------- 通用 100xx ----------
    public const PARAM_INVALID = 10000;      // 参数错误 / 校验失败 → 400
    public const UNAUTHENTICATED = 10001;    // 未认证 / 令牌无效 → 401
    public const FORBIDDEN = 10002;          // 无权限（含演示开关未开启）→ 403
    public const NOT_FOUND = 10003;          // 资源不存在 → 404
    public const CONFLICT = 10004;           // 冲突（唯一性、重复操作）→ 409
    public const CSRF_FAILED = 10005;        // CSRF 校验失败 → 419
    public const RATE_LIMITED = 10006;       // 频率限制 → 429
    public const ACCOUNT_LOCKED = 10007;     // 账号锁定 → 423
    public const BUSINESS_RULE = 10008;      // 语义 / 业务规则不满足 → 422

    // ---------- 认证 101xx ----------
    public const AUTH_BAD_CREDENTIALS = 10101; // 账号或密码错误（统一文案，防枚举）→ 401
    public const AUTH_CODE_WRONG = 10102;      // 验证码错误
    public const AUTH_CODE_EXPIRED = 10103;    // 验证码过期
    public const AUTH_CODE_USED = 10104;       // 验证码已使用
    public const AUTH_CODE_TOO_FREQUENT = 10105; // 验证码发送过于频繁 → 429
    public const AUTH_NEED_TOTP = 10106;       // 需要二次验证（2FA）→ 200（业务分支）
    public const AUTH_ACCOUNT_DISABLED = 10107; // 账号已被禁用
    public const AUTH_RESET_INVALID = 10108;   // 重置凭证无效或过期
    public const AUTH_OLD_PASSWORD_WRONG = 10109; // 原密码错误

    // ---------- OAuth 111xx ----------
    public const OAUTH_STATE_INVALID = 11101;  // state 校验失败
    public const OAUTH_PROVIDER_FAILED = 11102; // 第三方授权失败
    public const OAUTH_ALREADY_BOUND = 11103;  // 该第三方账号已绑定
    public const OAUTH_UNBIND_DENIED = 11104;  // 解绑失败（保留最后登录凭证）

    // ---------- 2FA 121xx ----------
    public const TOTP_CODE_WRONG = 12101;      // 动态码错误
    public const TOTP_CODE_REPLAY = 12102;     // 动态码已使用（重放）
    public const TOTP_RECOVERY_INVALID = 12103; // 恢复码无效或已用
    public const TOTP_NOT_ENABLED = 12104;     // 2FA 未启用
    public const TOTP_ALREADY_ENABLED = 12105; // 2FA 已启用

    // ---------- 设备 131xx ----------
    public const DEVICE_NOT_FOUND = 13101;     // 设备不存在
    public const DEVICE_REVOKED = 13102;       // 设备已吊销

    // ---------- KYC 141xx ----------
    public const KYC_LEVEL_DENIED = 14101;     // 等级状态不允许该操作
    public const KYC_L2_FAILED = 14102;        // 三要素校验失败
    public const KYC_L3_FAILED = 14103;        // 活体校验失败
    public const KYC_CALLBACK_SIGN_FAILED = 14104; // 回调验签失败
    public const KYC_RECORD_NOT_FOUND = 14105; // 实名记录不存在

    // ---------- 日志 151xx ----------
    public const LOG_AUDIT_FORBIDDEN = 15101;  // 无权限查询审计日志

    // ---------- 用户 161xx ----------
    public const USER_TAKEN = 16101;           // 用户名 / 邮箱 / 手机号已被占用
    public const USER_PHONE_BOUND = 16102;     // 手机号已绑定
    public const USER_EMAIL_BOUND = 16103;     // 邮箱已绑定

    // ---------- 通知 171xx ----------
    public const NOTIFICATION_NOT_FOUND = 17101; // 通知不存在

    private const HTTP_MAP = [
        self::PARAM_INVALID => 400,
        self::UNAUTHENTICATED => 401,
        self::FORBIDDEN => 403,
        self::NOT_FOUND => 404,
        self::CONFLICT => 409,
        self::CSRF_FAILED => 419,
        self::RATE_LIMITED => 429,
        self::ACCOUNT_LOCKED => 423,
        self::BUSINESS_RULE => 422,
        self::AUTH_BAD_CREDENTIALS => 401,
        self::AUTH_CODE_WRONG => 422,
        self::AUTH_CODE_EXPIRED => 422,
        self::AUTH_CODE_USED => 422,
        self::AUTH_CODE_TOO_FREQUENT => 429,
        self::AUTH_NEED_TOTP => 200,
        self::AUTH_ACCOUNT_DISABLED => 403,
        self::AUTH_RESET_INVALID => 422,
        self::AUTH_OLD_PASSWORD_WRONG => 422,
        self::OAUTH_STATE_INVALID => 400,
        self::OAUTH_PROVIDER_FAILED => 502,
        self::OAUTH_ALREADY_BOUND => 409,
        self::OAUTH_UNBIND_DENIED => 422,
        self::TOTP_CODE_WRONG => 422,
        self::TOTP_CODE_REPLAY => 422,
        self::TOTP_RECOVERY_INVALID => 422,
        self::TOTP_NOT_ENABLED => 422,
        self::TOTP_ALREADY_ENABLED => 422,
        self::DEVICE_NOT_FOUND => 404,
        self::DEVICE_REVOKED => 410,
        self::KYC_LEVEL_DENIED => 422,
        self::KYC_L2_FAILED => 422,
        self::KYC_L3_FAILED => 422,
        self::KYC_CALLBACK_SIGN_FAILED => 422,
        self::KYC_RECORD_NOT_FOUND => 404,
        self::LOG_AUDIT_FORBIDDEN => 403,
        self::USER_TAKEN => 409,
        self::USER_PHONE_BOUND => 409,
        self::USER_EMAIL_BOUND => 409,
        self::NOTIFICATION_NOT_FOUND => 404,
    ];

    /**
     * 错误码 → HTTP 状态码。
     */
    public static function httpStatus(int $code): int
    {
        return self::HTTP_MAP[$code] ?? 422;
    }

    /**
     * 错误码 → 用户可读默认文案（Controller/Service 可覆盖）。
     */
    public static function message(int $code): string
    {
        return match ($code) {
            self::PARAM_INVALID => '参数错误',
            self::UNAUTHENTICATED => '未认证或登录已过期',
            self::FORBIDDEN => '无权限',
            self::NOT_FOUND => '资源不存在',
            self::CONFLICT => '操作冲突',
            self::CSRF_FAILED => '安全校验失败',
            self::RATE_LIMITED => '请求过于频繁，请稍后再试',
            self::ACCOUNT_LOCKED => '账号已锁定，请稍后再试',
            self::BUSINESS_RULE => '业务规则不满足',
            self::AUTH_BAD_CREDENTIALS => '账号或密码错误',
            self::AUTH_CODE_WRONG => '验证码错误',
            self::AUTH_CODE_EXPIRED => '验证码已过期',
            self::AUTH_CODE_USED => '验证码已使用',
            self::AUTH_CODE_TOO_FREQUENT => '验证码发送过于频繁',
            self::AUTH_NEED_TOTP => '需要二次验证',
            self::AUTH_ACCOUNT_DISABLED => '账号已被禁用',
            self::AUTH_RESET_INVALID => '重置凭证无效或已过期',
            self::AUTH_OLD_PASSWORD_WRONG => '原密码错误',
            self::OAUTH_STATE_INVALID => '授权状态校验失败，请重新发起',
            self::OAUTH_PROVIDER_FAILED => '第三方授权失败',
            self::OAUTH_ALREADY_BOUND => '该第三方账号已绑定',
            self::OAUTH_UNBIND_DENIED => '无法解绑：请先设置密码或绑定手机号/邮箱',
            self::TOTP_CODE_WRONG => '动态码错误',
            self::TOTP_CODE_REPLAY => '动态码已使用，请刷新后重试',
            self::TOTP_RECOVERY_INVALID => '恢复码无效或已使用',
            self::TOTP_NOT_ENABLED => '2FA 未启用',
            self::TOTP_ALREADY_ENABLED => '2FA 已启用',
            self::DEVICE_NOT_FOUND => '设备不存在',
            self::DEVICE_REVOKED => '设备已吊销',
            self::KYC_LEVEL_DENIED => '当前实名等级不允许该操作',
            self::KYC_L2_FAILED => '三要素校验失败',
            self::KYC_L3_FAILED => '活体校验失败',
            self::KYC_CALLBACK_SIGN_FAILED => '回调验签失败',
            self::KYC_RECORD_NOT_FOUND => '实名记录不存在',
            self::LOG_AUDIT_FORBIDDEN => '无权限查询审计日志',
            self::USER_TAKEN => '用户名、邮箱或手机号已被占用',
            self::USER_PHONE_BOUND => '该手机号已绑定其他账号',
            self::USER_EMAIL_BOUND => '该邮箱已绑定其他账号',
            self::NOTIFICATION_NOT_FOUND => '通知不存在',
            default => '系统繁忙',
        };
    }
}
