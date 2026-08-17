<template>
  <div class="auth-shell">
    <div class="auth-hero">
      <h1>创建账号 ✨</h1>
      <p>注册 mem-reg 账号</p>
    </div>
    <div class="auth-box">
      <el-input v-model="form.username" placeholder="用户名（3-32 位字母数字下划线）" size="large" style="margin-bottom: 12px" />
      <el-input v-model="form.phone" placeholder="手机号" size="large" style="margin-bottom: 12px" />
      <el-input v-model="form.password" type="password" placeholder="密码（8-72 位，含字母与数字）" size="large" show-password style="margin-bottom: 12px" />
      <div style="display: flex; gap: 8px">
        <el-input v-model="form.code" placeholder="短信验证码" size="large" />
        <el-button size="large" :disabled="countdown > 0" style="width: 120px" @click="sendSms">
          {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
        </el-button>
      </div>

      <button class="app-btn-primary" :disabled="loading" @click="register">注 册</button>
      <div style="text-align: center; margin-top: 16px">
        <router-link class="app-link" to="/login">已有账号？去登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { authApi } from '../api'
import { useUserStore } from '../stores/user'

const router = useRouter()
const store = useUserStore()
const loading = ref(false)
const countdown = ref(0)
let timer = null

const form = reactive({ username: '', phone: '', password: '', code: '' })

async function sendSms() {
  try {
    // 默认区号 +86（后端 LOGIN_DEFAULT_COUNTRY_CODE 可配）
    const { data } = await authApi.smsSend({ country_code: '+86', phone: form.phone, scene: 1 })
    // dev 演示：验证码自动回填输入框
    if (data.data.mock_code) {
      form.code = data.data.mock_code
      ElMessage.success('验证码已发送并自动填入')
    } else {
      ElMessage.success('验证码已发送')
    }
    countdown.value = 60
    timer = setInterval(() => {
      countdown.value--
      if (countdown.value <= 0) clearInterval(timer)
    }, 1000)
  } catch (e) { /* 已提示 */ }
}

async function register() {
  loading.value = true
  try {
    const { data } = await authApi.register({
      username: form.username,
      country_code: '+86',
      phone: form.phone,
      password: form.password,
      code: form.code,
    })
    store.setUser(data.data.user)
    ElMessage.success('注册成功')
    router.push('/')
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
onBeforeUnmount(() => timer && clearInterval(timer))
</script>
