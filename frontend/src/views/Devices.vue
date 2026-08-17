<template>
  <AppPage title="设备管理">
    <div class="app-card" v-for="row in items" :key="row.id">
      <div style="display: flex; align-items: center; gap: 12px">
        <div class="app-menu-icon" style="background: #22c55e">📱</div>
        <div style="flex: 1">
          <div style="font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px">
            {{ row.device_name }}
            <el-tag v-if="row.is_current" type="success" size="small">当前设备</el-tag>
            <el-tag v-if="row.is_trusted" type="warning" size="small">信任</el-tag>
          </div>
          <div class="text-muted" style="margin-top: 4px">
            {{ row.last_ip }} · {{ row.last_ip_location || '未知地点' }} · {{ formatTime(row.last_active_at) }}
          </div>
        </div>
        <el-button size="small" round @click="toggleTrust(row)">
          {{ row.is_trusted ? '取消信任' : '信任' }}
        </el-button>
        <el-button size="small" round type="danger" :disabled="row.is_current" @click="kick(row)">
          踢下线
        </el-button>
      </div>
    </div>

    <el-empty v-if="!items.length && !loading" description="暂无设备" :image-size="80" />

    <el-button v-if="nextCursor" style="width: 100%; margin-top: 4px" @click="loadMore">加载更多</el-button>

    <button class="app-logout-btn" style="margin-top: 12px" @click="logoutAll">登出全部设备</button>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { deviceApi, authApi } from '../api'
import { formatTime } from '../utils/format'
import { useUserStore } from '../stores/user'

const router = useRouter()
const store = useUserStore()

const items = ref([])
const nextCursor = ref(null)
const loading = ref(false)

onMounted(load)

async function load() {
  loading.value = true
  try {
    const { data } = await deviceApi.list({ cursor: 0, per_page: 20 })
    items.value = data.data.items
    nextCursor.value = data.data.next_cursor
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}

async function loadMore() {
  const { data } = await deviceApi.list({ cursor: nextCursor.value, per_page: 20 })
  items.value.push(...data.data.items)
  nextCursor.value = data.data.next_cursor
}

async function toggleTrust(row) {
  await deviceApi.trust(row.id, !row.is_trusted)
  row.is_trusted = !row.is_trusted
  ElMessage.success('已更新')
}

async function kick(row) {
  await ElMessageBox.confirm(`确定踢下线设备「${row.device_name}」吗？`, '提示', { type: 'warning' })
  await deviceApi.kick(row.id)
  items.value = items.value.filter((d) => d.id !== row.id)
  ElMessage.success('已踢下线')
}

async function logoutAll() {
  await ElMessageBox.confirm('将登出全部设备并使所有已签发令牌失效，确定继续？', '提示', { type: 'warning' })
  await authApi.logoutAll()
  store.reset()
  ElMessage.success('已登出全部设备')
  router.push('/login')
}
</script>
