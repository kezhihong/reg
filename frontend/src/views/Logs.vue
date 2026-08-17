<template>
  <AppPage title="登录日志">
    <div class="app-card" v-for="row in items" :key="row.id">
      <div style="display: flex; align-items: center; gap: 12px">
        <div class="app-menu-icon" :style="{ background: row.is_success ? '#22c55e' : '#ef4444' }">
          {{ row.is_success ? '✅' : '❌' }}
        </div>
        <div style="flex: 1">
          <div style="font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px">
            {{ LoginTypeText[row.login_type] }}
            <el-tag :type="row.is_success ? 'success' : 'danger'" size="small">
              {{ row.is_success ? '成功' : '失败' }}
            </el-tag>
            <el-tag v-if="row.is_unusual" type="warning" size="small">异地</el-tag>
          </div>
          <div class="text-muted" style="margin-top: 4px">
            {{ row.ip }} · {{ row.ip_location || '未知地点' }} · {{ row.device_name || '未知设备' }}
          </div>
          <div class="text-muted" style="margin-top: 2px">{{ formatTime(row.created_at) }}</div>
        </div>
      </div>
    </div>

    <el-empty v-if="!items.length" description="暂无登录记录" :image-size="80" />
    <el-button v-if="nextCursor" style="width: 100%" @click="loadMore">加载更多</el-button>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AppPage from '../components/AppPage.vue'
import { logApi } from '../api'
import { LoginTypeText } from '../constants'
import { formatTime } from '../utils/format'

const items = ref([])
const nextCursor = ref(null)

onMounted(load)

async function load() {
  try {
    const { data } = await logApi.login({ cursor: 0, per_page: 20 })
    items.value = data.data.items
    nextCursor.value = data.data.next_cursor
  } catch (e) { /* 已提示 */ }
}

async function loadMore() {
  const { data } = await logApi.login({ cursor: nextCursor.value, per_page: 20 })
  items.value.push(...data.data.items)
  nextCursor.value = data.data.next_cursor
}
</script>
