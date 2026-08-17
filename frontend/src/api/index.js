import http from './http'

/** 认证模块（docs/02 §2） */
export const authApi = {
  register: (data) => http.post('/auth/register', data),
  login: (data) => http.post('/auth/login', data),
  smsSend: (data) => http.post('/auth/sms/send', data),
  smsLogin: (data) => http.post('/auth/login/sms', data),
  refresh: () => http.post('/auth/refresh', {}),
  logout: () => http.post('/auth/logout', {}),
  logoutAll: () => http.post('/auth/logout-all', {}),
  forgotPassword: (data) => http.post('/auth/forgot-password', data),
  resetPassword: (data) => http.post('/auth/reset-password', data),
  changePassword: (data) => http.post('/auth/change-password', data),
  check: () => http.get('/auth/check'),
}

/** 2FA 模块（docs/02 §4） */
export const totpApi = {
  enableStart: () => http.post('/2fa/enable/start', {}),
  enableVerify: (data) => http.post('/2fa/enable/verify', data),
  disable: (data) => http.post('/2fa/disable', data),
  loginVerify: (data) => http.post('/2fa/login/verify', data),
  recoveryVerify: (data) => http.post('/2fa/recovery/verify', data),
  recoveryCodes: () => http.get('/2fa/recovery-codes'),
  singleRecoveryCode: (index) => http.get(`/2fa/recovery-codes/${index}`),
}

/** 设备模块（docs/02 §5） */
export const deviceApi = {
  list: (params) => http.get('/devices', { params }),
  kick: (id) => http.delete(`/devices/${id}`),
  trust: (id, trusted) => http.put(`/devices/${id}/trust`, { trusted }),
}

/** KYC 模块（docs/02 §6） */
export const kycApi = {
  status: () => http.get('/kyc'),
  l1: (data) => http.post('/kyc/l1', data),
  l2Submit: (data) => http.post('/kyc/l2/submit', data),
  l2Result: (params) => http.get('/kyc/l2/result', { params }),
  l3Submit: (data) => http.post('/kyc/l3/submit', data),
  records: (params) => http.get('/kyc/records', { params }),
}

/** 日志模块（docs/02 §7） */
export const logApi = {
  login: (params) => http.get('/logs/login', { params }),
  audit: (params) => http.get('/logs/audit', { params }),
}

/** 用户模块（docs/02 §8） */
export const userApi = {
  me: () => http.get('/user/me'),
  updateProfile: (data) => http.put('/user/me', data),
  bindPhone: (data) => http.put('/user/me/phone', data),
  bindEmail: (data) => http.put('/user/me/email', data),
  uploadAvatar: (formData) => http.post('/user/me/avatar', formData),
}

/** OAuth 模块（docs/02 §3） */
export const oauthApi = {
  authorizeUrl: (provider, redirectUri) =>
    `/api/v1/oauth/${provider}/authorize?redirect_uri=${encodeURIComponent(redirectUri)}`,
  bind: (provider, redirectUri) => http.post(`/oauth/${provider}/bind`, { redirect_uri: redirectUri }),
  unbind: (provider) => http.delete(`/oauth/${provider}/unbind`),
  bound: () => http.get('/oauth/bound'),
}

/** 通知模块（docs/02 §9） */
export const notificationApi = {
  list: (params) => http.get('/notifications', { params }),
  detail: (id) => http.get(`/notifications/${id}`),
}
