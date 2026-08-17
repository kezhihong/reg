<template>
  <div class="app-shell">
    <!-- 用户信息头部 -->
    <div class="app-profile-header">
      <div class="app-profile-row">
        <img v-if="me.avatar_url" :src="me.avatar_url" class="app-avatar-img" alt="头像" />
        <div v-else class="app-avatar">{{ avatarChar }}</div>
        <div style="flex: 1">
          <div class="app-username">{{ me.username || '未登录' }}</div>
          <div class="app-user-sub">
            <span>实名：{{ kycText(me.kyc_level) }}</span>
          </div>          <div class="app-user-sub">
            <span>{{ me.phone || '未绑定手机' }}</span>
            <span>·</span>
            <span>{{ me.email || '未绑定邮箱' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 统计 -->
    <div style="padding: 12px">
      <div class="app-card" style="padding: 10px 6px">
        <div class="app-stats">
          <div class="app-stat" @click="$router.push('/devices')">
            <div class="num">{{ stats.devices }}</div>
            <div class="label">设备</div>
          </div>
          <div class="app-stat" @click="$router.push('/logs')">
            <div class="num">{{ stats.logins }}</div>
            <div class="label">登录记录</div>
          </div>
          <div class="app-stat" @click="$router.push('/notifications')">
            <div class="num">{{ stats.notifications }}</div>
            <div class="label">通知</div>
          </div>
          <div class="app-stat" @click="$router.push('/kyc')">
            <div class="num">{{ me.kyc_level >= 1 ? '✓' : '—' }}</div>
            <div class="label">实名认证</div>
          </div>
        </div>
      </div>

      <!-- 功能菜单 -->
      <div class="app-card" style="padding: 6px 14px">
        <div class="app-menu-item" @click="$router.push('/profile')">
          <div class="app-menu-icon" style="background: #4f7cff">👤</div>
          <div class="app-menu-label">
            个人资料
            <div class="app-menu-desc">头像、昵称、绑定手机与邮箱</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
        <div class="app-menu-item" @click="$router.push('/security')">
          <div class="app-menu-icon" style="background: #f5a623">🔒</div>
          <div class="app-menu-label">
            账号安全
            <div class="app-menu-desc">密码、第三方账号绑定</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
        <div class="app-menu-item" @click="$router.push('/devices')">
          <div class="app-menu-icon" style="background: #22c55e">📱</div>
          <div class="app-menu-label">
            设备管理
            <div class="app-menu-desc">查看与踢出登录设备</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
        <div class="app-menu-item" @click="$router.push('/kyc')">
          <div class="app-menu-icon" style="background: #a855f7">🛡️</div>
          <div class="app-menu-label">
            实名认证
            <div class="app-menu-desc">{{ kycText(me.kyc_level) }}，通过手机号验证</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
        <div class="app-menu-item" @click="$router.push('/logs')">
          <div class="app-menu-icon" style="background: #06b6d4">📋</div>
          <div class="app-menu-label">
            登录日志
            <div class="app-menu-desc">最近登录记录与异地提醒</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
        <div class="app-menu-item" @click="$router.push('/notifications')">
          <div class="app-menu-icon" style="background: #ef4444">🔔</div>
          <div class="app-menu-label">
            通知中心
            <div class="app-menu-desc">安全提醒与告警消息</div>
          </div>
          <span class="app-menu-arrow">›</span>
        </div>
      </div>

      <!-- 退出登录 -->
      <button class="app-logout-btn" @click="logout">退出登录</button>
      <p style="text-align: center; color: #c0c4cc; font-size: 12px; margin-top: 14px">
        mem-reg · 生产级登录注册系统
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { authApi, deviceApi, logApi, notificationApi, userApi } from '../api'
import { useUserStore } from '../stores/user'

const router = useRouter()
const store = useUserStore()

const me = ref({})
const stats = ref({ devices: 0, logins: 0, notifications: 0 })

const avatarChar = computed(() => (me.value.username || '?').charAt(0).toUpperCase())
const kycText = (l) => (l >= 1 ? '已实名' : '未实名')

onMounted(async () => {
  try {
    const { data } = await userApi.me()
    me.value = data.data
    store.setUser({ id: data.data.id, username: data.data.username, kyc_level: data.data.kyc_level })

    // 统计：per_page=100 取全量计数（设备通常较少，日志/通知按上限显示）
    const [dev, logs, notif] = await Promise.all([
      deviceApi.list({ per_page: 100 }),
      logApi.login({ per_page: 100 }),
      notificationApi.list({ per_page: 100 }),
    ])
    stats.value.devices = dev.data.data.items.length
    stats.value.logins = logs.data.data.items.length
    stats.value.notifications = notif.data.data.items.length
  } catch (e) { /* 拦截器处理 */ }
})

async function logout() {
  await ElMessageBox.confirm('确定退出当前设备吗？', '提示', { type: 'warning' })
  await authApi.logout()
  store.reset()
  ElMessage.success('已退出登录')
  router.push('/login')
}
</script>
