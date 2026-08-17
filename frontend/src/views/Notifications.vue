<template>
  <AppPage title="通知中心">
    <div class="app-card" v-for="row in items" :key="row.id" @click="showDetail(row)" style="cursor: pointer">
      <div style="display: flex; align-items: center; gap: 12px">
        <div class="app-menu-icon" :style="{ background: sceneColor(row.scene) }">🔔</div>
        <div style="flex: 1">
          <div style="font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px">
            {{ NotificationSceneText[row.scene] || '通知' }}
            <el-tag :type="statusType(row.status)" size="small">{{ statusText(row.status) }}</el-tag>
          </div>
          <div class="text-muted" style="margin-top: 4px">{{ row.title }}</div>
          <div class="text-muted" style="margin-top: 2px">{{ formatTime(row.created_at) }}</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
    </div>

    <el-empty v-if="!items.length" description="暂无通知" :image-size="80" />
    <el-button v-if="nextCursor" style="width: 100%" @click="loadMore">加载更多</el-button>

    <el-dialog v-model="detailVisible" title="通知详情" width="92%" style="max-width: 400px">
      <div style="font-size: 15px; font-weight: 600; margin-bottom: 8px">{{ detail.title }}</div>
      <p class="text-muted" style="margin: 0 0 8px">收件人：{{ detail.recipient }} · {{ formatTime(detail.sent_at) }}</p>
      <div style="background: #f7f8fa; border-radius: 8px; padding: 12px; font-size: 14px; line-height: 1.6">
        {{ detail.content }}
      </div>
    </el-dialog>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AppPage from '../components/AppPage.vue'
import { notificationApi } from '../api'
import { NotificationSceneText } from '../constants'
import { formatTime } from '../utils/format'

const items = ref([])
const nextCursor = ref(null)
const detailVisible = ref(false)
const detail = ref({})

const statusText = (s) => ({ 1: '待发', 2: '已发', 3: '失败', 4: '死信' }[s] ?? '-')
const statusType = (s) => ({ 1: 'info', 2: 'success', 3: 'warning', 4: 'danger' }[s] ?? 'info')
const sceneColor = (s) => ({ 1: '#ef4444', 2: '#f5a623', 3: '#06b6d4' }[s] ?? '#909399')

onMounted(load)

async function load() {
  try {
    const { data } = await notificationApi.list({ cursor: 0, per_page: 20 })
    items.value = data.data.items
    nextCursor.value = data.data.next_cursor
  } catch (e) { /* 已提示 */ }
}

async function loadMore() {
  const { data } = await notificationApi.list({ cursor: nextCursor.value, per_page: 20 })
  items.value.push(...data.data.items)
  nextCursor.value = data.data.next_cursor
}

async function showDetail(row) {
  const { data } = await notificationApi.detail(row.id)
  detail.value = data.data
  detailVisible.value = true
}
</script>
