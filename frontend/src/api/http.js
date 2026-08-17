import axios from 'axios'
import { ElMessage } from 'element-plus'
import router from '../router'

/**
 * HTTP 客户端（docs/01 §7.2）：
 * - Cookie 凭据模式（withCredentials）
 * - 401 静默刷新（单飞：并发 401 合并为一次 /auth/refresh 后重放）
 * - X-CSRF-Token 注入（值 = 服务端种下的 csrf_token Cookie，docs/04 §3.2）
 * - X-Request-Id 透传（docs/02 §1.1）
 */

const http = axios.create({
  baseURL: '/api/v1',
  timeout: 15000,
  withCredentials: true,
})

let refreshing = null

function readCookie(name) {
  const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'))
  return m ? decodeURIComponent(m[1]) : ''
}

async function forceLogout() {
  const { useUserStore } = await import('../stores/user')
  useUserStore().reset()
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
}

// 请求拦截：CSRF 头 + 请求 ID
http.interceptors.request.use((config) => {
  const csrf = readCookie('csrf_token')
  if (csrf) config.headers['X-CSRF-Token'] = csrf
  if (!config.headers['X-Request-Id']) {
    config.headers['X-Request-Id'] = crypto.randomUUID
      ? crypto.randomUUID().replace(/-/g, '')
      : Date.now().toString(36)
  }
  return config
})

// 响应拦截：401 静默刷新（单飞）；公开认证接口的 401/业务失败直接提示
http.interceptors.response.use(
  (res) => res,
  async (error) => {
    const { response, config } = error
    if (!response) {
      ElMessage.error('网络错误，请稍后重试')
      return Promise.reject(error)
    }

    // 公开认证接口：业务失败（401 账号密码错误等）直接提示，不走静默刷新
    const PUBLIC_AUTH = [
      '/auth/login',
      '/auth/register',
      '/auth/sms/send',
      '/auth/login/sms',
      '/auth/forgot-password',
      '/auth/reset-password',
      '/2fa/login/verify',
      '/2fa/recovery/verify',
    ]
    if (PUBLIC_AUTH.some((p) => config.url.includes(p))) {
      if (response.data && response.data.message) {
        ElMessage.error(response.data.message)
      }
      return Promise.reject(error)
    }

    if (response.status !== 401) {
      if (response.data && response.data.message) {
        ElMessage.error(response.data.message)
      }
      return Promise.reject(error)
    }

    // 刷新接口自身 401 → 直接登出
    if (config.url.includes('/auth/refresh')) {
      await forceLogout()
      return Promise.reject(error)
    }

    try {
      if (!refreshing) {
        refreshing = http.post('/auth/refresh', {}).finally(() => {
          refreshing = null
        })
      }
      await refreshing
      return http(config) // 重放原请求
    } catch (e) {
      await forceLogout()
      return Promise.reject(e)
    }
  }
)

export default http
