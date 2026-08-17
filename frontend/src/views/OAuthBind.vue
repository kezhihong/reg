<template>
  <AppPage title="第三方账号绑定">
    <div class="app-card" style="padding: 6px 14px">
      <div
        v-for="p in providers"
        :key="p.name"
        class="app-menu-item"
        @click="bound.includes(p.name) ? unbindOauth(p.name) : bindOauth(p.name)"
      >
        <div class="app-menu-icon" :style="{ background: p.name === 'github' ? '#24292f' : '#4285f4' }">{{ p.icon }}</div>
        <div class="app-menu-label">
          {{ p.label }}
          <div class="app-menu-desc">{{ bound.includes(p.name) ? '已绑定，点击解绑' : '未绑定，点击绑定' }}</div>
        </div>
        <el-tag v-if="bound.includes(p.name)" type="success" size="small">已绑定</el-tag>
        <span v-else class="app-menu-arrow">›</span>
      </div>
    </div>
    <p class="text-muted" style="padding: 0 12px">
      绑定后可使用第三方账号快捷登录；解绑前请确保已设置密码或绑定手机/邮箱。
    </p>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { oauthApi } from '../api'

const route = useRoute()

const providers = [
  { name: 'github', label: 'GitHub', icon: '🐙' },
  { name: 'google', label: 'Google', icon: '🌐' },
]
const bound = ref([])

onMounted(async () => {
  // 处理第三方回调结果（?code=0 成功 / 其他失败，docs/02 §3.2）
  if (route.query.code) {
    if (route.query.code === '0') {
      ElMessage.success('绑定成功')
    } else {
      ElMessage.error(`绑定失败（code=${route.query.code}）`)
    }
    // 清理 URL 参数
    history.replaceState({}, '', '/security/oauth')
  }
  try {
    const { data } = await oauthApi.bound()
    bound.value = data.data.items.map((i) => i.provider)
  } catch (e) { /* 已提示 */ }
})

async function bindOauth(provider) {
  // 尚未申请第三方 OAuth App 凭据：直接友好提示（docs/05 §3.3；后端门禁双保险）
  ElMessage.info(`${provider === 'github' ? 'GitHub' : 'Google'} 绑定暂未开通，敬请期待`)
}

async function unbindOauth(provider) {
  await ElMessageBox.confirm(`确定解绑 ${provider} 吗？`, '提示', { type: 'warning' })
  await oauthApi.unbind(provider)
  bound.value = bound.value.filter((p) => p !== provider)
  ElMessage.success('已解绑')
}
</script>
