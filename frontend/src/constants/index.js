/**
 * 前端状态常量（与后端常量一一对应，docs/03 表注释为唯一口径）。
 */
export const UserStatus = { NORMAL: 1, LOCKED: 2, DISABLED: 3 }
export const KycLevel = { L0: 0, L1: 1, L2: 2, L3: 3 }
export const VerificationScene = {
  LOGIN_REGISTER: 1,
  REGISTER: 2,
  RESET_PASSWORD: 3,
  BIND_PHONE: 4,
  CHANGE_EMAIL: 5,
  KYC_L1: 6,
}
export const LoginType = { PASSWORD: 1, SMS: 2, EMAIL: 3, GITHUB: 4, GOOGLE: 5 }
export const NotificationStatus = { PENDING: 1, SENT: 2, FAILED: 3, DEAD: 4 }
export const KycStatus = { SUBMITTING: 1, REVIEWING: 2, APPROVED: 3, REJECTED: 4, EXPIRED: 5 }

export const KycStatusText = {
  [KycStatus.SUBMITTING]: '提交中',
  [KycStatus.REVIEWING]: '复核中',
  [KycStatus.APPROVED]: '已通过',
  [KycStatus.REJECTED]: '已驳回',
  [KycStatus.EXPIRED]: '已过期',
}

export const LoginTypeText = {
  [LoginType.PASSWORD]: '密码',
  [LoginType.SMS]: '短信验证码',
  [LoginType.EMAIL]: '邮箱',
  [LoginType.GITHUB]: 'GitHub',
  [LoginType.GOOGLE]: 'Google',
}

export const NotificationSceneText = {
  1: '异地登录告警',
  2: '重置密码',
  3: '安全提醒',
}
