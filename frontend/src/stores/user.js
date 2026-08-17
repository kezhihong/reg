import { defineStore } from 'pinia'

/**
 * 用户状态（docs/01 §7.2）：仅存脱敏后的基本信息；令牌在 httpOnly Cookie，不落 localStorage。
 */
export const useUserStore = defineStore('user', {
  state: () => ({
    user: null,
    loaded: false,
  }),
  getters: {
    isLoggedIn: (s) => !!s.user,
    username: (s) => s.user?.username || '',
    kycLevel: (s) => s.user?.kyc_level ?? 0,
  },
  actions: {
    setUser(user) {
      this.user = user
      this.loaded = true
    },
    async fetchMe() {
      const { userApi } = await import('../api')
      const { data } = await userApi.me()
      this.setUser(data.data)
      return data.data
    },
    reset() {
      this.user = null
      this.loaded = false
    },
  },
})
