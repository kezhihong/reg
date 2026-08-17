<template>
  <AppPage title="修改密码">
    <div class="app-card">
      <el-input v-model="pwd.old_password" type="password" placeholder="原密码" size="large" show-password style="margin-bottom: 12px" />
      <el-input v-model="pwd.new_password" type="password" placeholder="新密码（8-72 位，含字母与数字）" size="large" show-password />
      <button class="app-btn-primary" :disabled="loading" @click="changePassword">确认修改</button>
      <p class="text-muted" style="margin: 10px 0 0">修改成功后全部设备将下线，需重新登录。</p>
    </div>
  </AppPage>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { authApi } from '../api'
import { useUserStore } from '../stores/user'

const router = useRouter()
const store = useUserStore()
const loading = ref(false)
const pwd = reactive({ old_password: '', new_password: '' })

async function changePassword() {
  await ElMessageBox.confirm('修改密码后全部设备将下线，需重新登录，确定继续？', '提示', { type: 'warning' })
  loading.value = true
  try {
    await authApi.changePassword(pwd)
    store.reset()
    ElMessage.success('密码已修改，请重新登录')
    router.push('/login')
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>
