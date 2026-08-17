<template>
  <AppPage title="绑定 / 更换邮箱">
    <div class="app-card">
      <div class="app-card-title">新邮箱</div>
      <p class="text-muted" style="margin: 0 0 10px">
        当前绑定：{{ me.email || '未绑定' }}（发送验证码至新邮箱完成绑定）
      </p>
      <el-input v-model="form.email" placeholder="新邮箱" size="large" style="margin-bottom: 12px" />
      <div style="display: flex; gap: 8px; margin-bottom: 12px">
        <el-input v-model="form.code" placeholder="6 位验证码" size="large" />
        <el-button size="large" :disabled="countdown > 0" style="width: 120px" @click="sendCode">
          {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
        </el-button>
      </div>
      <button class="app-btn-primary" :disabled="loading" @click="bind">确认绑定</button>
    </div>
  </AppPage>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { userApi, authApi } from '../api'

const me = ref({})
const loading = ref(false)
const countdown = ref(0)
let timer = null
const form = reactive({ email: '', code: '' })

onMounted(async () => {
  const { data } = await userApi.me()
  me.value = data.data
})
onBeforeUnmount(() => timer && clearInterval(timer))

async function sendCode() {
  const { data } = await authApi.smsSend({ email: form.email, scene: 5 })
  // dev 演示：验证码自动回填输入框
  if (data.data.mock_code) {
    form.code = data.data.mock_code
    ElMessage.success('验证码已发送并自动填入')
  } else {
    ElMessage.success('验证码已发送（dev 环境见后端日志）')
  }
  countdown.value = 60
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) clearInterval(timer)
  }, 1000)
}

async function bind() {
  loading.value = true
  try {
    const { data } = await userApi.bindEmail(form)
    me.value = data.data
    ElMessage.success('邮箱已绑定')
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>
