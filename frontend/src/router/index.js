import { createRouter, createWebHistory } from 'vue-router'

/**
 * 路由与守卫（docs/01 §7.2）：登录态校验（/auth/check）+ 2FA 中间步骤页。
 * 全部为顶层路由：个人中心主页与各功能页平级（功能页自带 AppPage 导航栏）。
 */
const routes = [
  { path: '/login', name: 'login', component: () => import('../views/Login.vue') },
  { path: '/register', name: 'register', component: () => import('../views/Register.vue') },
  { path: '/totp-verify', name: 'totp-verify', component: () => import('../views/TotpVerify.vue') },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/ForgotPassword.vue') },
  // 登录后主页 = App 个人中心
  { path: '/', name: 'home', component: () => import('../views/Layout.vue'), meta: { requiresAuth: true } },
  { path: '/profile', name: 'profile', component: () => import('../views/Profile.vue'), meta: { requiresAuth: true } },
  { path: '/profile/edit', name: 'profile-edit', component: () => import('../views/ProfileEdit.vue'), meta: { requiresAuth: true } },
  { path: '/profile/phone', name: 'profile-phone', component: () => import('../views/ProfilePhone.vue'), meta: { requiresAuth: true } },
  { path: '/profile/email', name: 'profile-email', component: () => import('../views/ProfileEmail.vue'), meta: { requiresAuth: true } },
  { path: '/security', name: 'security', component: () => import('../views/Security.vue'), meta: { requiresAuth: true } },
  { path: '/security/totp', name: 'security-totp', component: () => import('../views/TotpSetting.vue'), meta: { requiresAuth: true } },
  { path: '/security/password', name: 'security-password', component: () => import('../views/ChangePassword.vue'), meta: { requiresAuth: true } },
  { path: '/security/oauth', name: 'security-oauth', component: () => import('../views/OAuthBind.vue'), meta: { requiresAuth: true } },
  { path: '/devices', name: 'devices', component: () => import('../views/Devices.vue'), meta: { requiresAuth: true } },
  { path: '/kyc', name: 'kyc', component: () => import('../views/Kyc.vue'), meta: { requiresAuth: true } },
  { path: '/logs', name: 'logs', component: () => import('../views/Logs.vue'), meta: { requiresAuth: true } },
  { path: '/notifications', name: 'notifications', component: () => import('../views/Notifications.vue'), meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  if (to.meta.requiresAuth) {
    const { useUserStore } = await import('../stores/user')
    const store = useUserStore()
    try {
      const { authApi } = await import('../api')
      const { data } = await authApi.check()
      if (data.data.valid) {
        store.setUser(data.data.user)
        return true
      }
    } catch (e) {
      // 401 已由拦截器静默刷新；刷新失败会跳登录页
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  return true
})

export default router
