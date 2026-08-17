<template>
  <AppPage title="个人资料">
    <!-- 用户信息 -->
    <div class="app-card" style="text-align: center">
      <img v-if="me.avatar_url" :src="me.avatar_url" class="app-avatar-img" alt="头像" style="margin: 6px auto 12px" />
      <div v-else class="app-avatar" style="margin: 6px auto 12px; background: linear-gradient(135deg, #4f7cff, #7a5cff)">
        {{ (me.nickname || me.username || '?').charAt(0).toUpperCase() }}
      </div>
      <div style="font-size: 18px; font-weight: 700">{{ me.nickname || me.username }}</div>
      <div class="text-muted" style="margin-top: 4px">@{{ me.username }}</div>
      <div style="display: flex; justify-content: center; gap: 8px; margin-top: 10px">
        <el-tag :type="me.is_phone_verified ? 'success' : 'info'" size="small" round>手机已验</el-tag>
        <el-tag :type="me.is_email_verified ? 'success' : 'info'" size="small" round>邮箱已验</el-tag>
        <el-tag type="primary" size="small" round>{{ kycText(me.kyc_level) }}</el-tag>
      </div>
      <div class="text-muted" style="margin-top: 10px">
        {{ me.phone || '未绑定手机' }} · {{ me.email || '未绑定邮箱' }}
      </div>
    </div>

    <!-- 二级菜单 -->
    <div class="app-card" style="padding: 6px 14px">
      <div class="app-menu-item" @click="$router.push('/profile/edit')">
        <div class="app-menu-icon" style="background: #4f7cff">✏️</div>
        <div class="app-menu-label">
          修改资料
          <div class="app-menu-desc">头像与昵称</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
      <div class="app-menu-item" @click="$router.push('/profile/phone')">
        <div class="app-menu-icon" style="background: #22c55e">📱</div>
        <div class="app-menu-label">
          绑定 / 更换手机号
          <div class="app-menu-desc">{{ me.phone || '当前未绑定手机号' }}</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
      <div class="app-menu-item" @click="$router.push('/profile/email')">
        <div class="app-menu-icon" style="background: #06b6d4">✉️</div>
        <div class="app-menu-label">
          绑定 / 更换邮箱
          <div class="app-menu-desc">{{ me.email || '当前未绑定邮箱' }}</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
    </div>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AppPage from '../components/AppPage.vue'
import { userApi } from '../api'

const me = ref({})
const kycText = (l) => (l >= 1 ? '已实名' : '未实名')

onMounted(async () => {
  try {
    const { data } = await userApi.me()
    me.value = data.data
  } catch (e) { /* 拦截器处理 */ }
})
</script>
