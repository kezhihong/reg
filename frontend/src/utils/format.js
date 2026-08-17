/**
 * 前端双保险脱敏（docs/02 §1.7：服务端为唯一脱敏源，前端仅兜底展示）。
 */
export function maskPhone(phone) {
  if (!phone) return ''
  if (phone.length <= 7) return '*'.repeat(phone.length)
  return phone.slice(0, 3) + '*'.repeat(phone.length - 7) + phone.slice(-4)
}

export function maskEmail(email) {
  if (!email) return ''
  const idx = email.indexOf('@')
  if (idx <= 0) return '***'
  return email[0] + '***' + email.slice(idx)
}

export function maskIdCard(id) {
  if (!id) return ''
  if (id.length <= 8) return '*'.repeat(id.length)
  return id.slice(0, 4) + '*'.repeat(id.length - 8) + id.slice(-4)
}

export function maskRealName(name) {
  if (!name) return ''
  return name[0] + '*'.repeat(Math.max(1, name.length - 1))
}

export function formatTime(ts) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}
