<?php

declare(strict_types=1);

namespace App\Constants;

final class AuditAction
{
    public const LOGIN_SUCCESS = 'auth.login_success';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOCKOUT = 'auth.lockout';
    public const LOGOUT = 'auth.logout';
    public const LOGOUT_ALL = 'auth.logout_all';
    public const PASSWORD_CHANGED = 'auth.password_changed';
    public const PASSWORD_RESET = 'auth.password_reset';
    public const TOTP_ENABLED = 'auth.2fa_enabled';
    public const TOTP_DISABLED = 'auth.2fa_disabled';
    public const RECOVERY_CODE_USED = 'auth.recovery_code_used';
    public const REFRESH_REUSE_DETECTED = 'refresh.reuse_detected';
    public const DEVICE_KICK = 'device.kick';
    public const DEVICE_TRUST_CHANGED = 'device.trust_changed';
    public const OAUTH_BIND = 'oauth.bind';
    public const OAUTH_UNBIND = 'oauth.unbind';
    public const KYC_SUBMITTED = 'kyc.submitted';
    public const KYC_RESULT_RECEIVED = 'kyc.result_received';
    public const KYC_CALLBACK_RECEIVED = 'kyc.callback_received';
}
