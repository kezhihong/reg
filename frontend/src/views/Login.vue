<template>
  <div class="auth-shell">
    <div class="auth-hero">
      <h1>欢迎回来 👋</h1>
      <p>登录 mem-reg 账号中心</p>
    </div>
    <div class="auth-box">
      <!-- 提示条 -->
      <el-alert
        v-if="notice"
        :title="notice"
        :type="noticeType"
        :closable="false"
        style="margin-bottom: 14px; border-radius: 10px"
      />

      <el-tabs v-model="tab" stretch>
        <el-tab-pane label="账号登录" name="pwd">
          <el-input v-model="form.account" placeholder="手机号 / 邮箱 / 用户名" size="large" style="margin-bottom: 12px" @keyup.enter="login" />
          <el-input v-model="form.password" type="password" placeholder="密码" size="large" show-password @keyup.enter="login" />
          <button class="app-btn-primary" :disabled="loading" @click="login">登 录</button>
        </el-tab-pane>
        <el-tab-pane label="短信登录" name="sms">
          <el-input v-model="sms.phone" placeholder="手机号" size="large" style="margin-bottom: 12px" />
          <div style="display: flex; gap: 8px; margin-bottom: 12px">
            <el-input v-model="sms.code" placeholder="6 位验证码" size="large" />
            <el-button size="large" :disabled="countdown > 0" style="width: 120px" @click="sendSms">
              {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
            </el-button>
          </div>
          <button class="app-btn-primary" :disabled="loading" @click="smsLogin">登录 / 注册</button>
        </el-tab-pane>
      </el-tabs>

      <div style="display: flex; justify-content: space-between; margin-top: 18px">
        <router-link class="app-link" to="/register">注册新账号</router-link>
        <router-link class="app-link" to="/forgot-password">忘记密码？</router-link>
      </div>

      <el-divider>第三方登录</el-divider>
      <div style="display: flex; gap: 16px; justify-content: center">
        <div style="text-align: center" @click="oauth('github')">
          <div class="app-menu-icon" style="background: #24292f; width: 44px; height: 44px; border-radius: 50%; font-size: 22px">🐙</div>
          <div class="text-muted" style="margin-top: 4px">GitHub</div>
        </div>
        <div style="text-align: center" @click="oauth('google')">
          <div class="app-menu-icon" style="background: #4285f4; width: 44px; height: 44px; border-radius: 50%; font-size: 22px">🌐</div>
          <div class="text-muted" style="margin-top: 4px">Google</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { authApi } from '../api'
import { useUserStore } from '../stores/user'

const router = useRouter()
const route = useRoute()
const store = useUserStore()

const tab = ref('pwd')
const loading = ref(false)
const countdown = ref(0)
let timer = null

const notice = ref('')
const noticeType = ref('info')

const form = reactive({ account: '', password: '' })
const sms = reactive({ phone: '', code: '' })

onMounted(() => {
  if (route.query.code) {
    notice.value = route.query.error
      ? `第三方登录失败（code=${route.query.code}）`
      : '第三方登录成功'
    noticeType.value = route.query.error ? 'error' : 'success'
    if (route.query.need_totp) {
      router.push({ name: 'totp-verify', query: { ticket: route.query.totp_ticket } })
    } else if (!route.query.error && route.query.code === '0') {
      router.push(route.query.redirect || '/')
    }
  }
})
onBeforeUnmount(() => timer && clearInterval(timer))

async function login() {
  loading.value = true
  try {
    const { data } = await authApi.login(form)
    await handleLoginResult(data.data)
  } catch (e) { /* 拦截器已提示 */ } finally {
    loading.value = false
  }
}

async function sendSms() {
  try {
    // 默认区号 +86（docs/03 §3.1 两段式；后端 LOGIN_DEFAULT_COUNTRY_CODE 可配）
    const { data } = await authApi.smsSend({ country_code: '+86', phone: sms.phone, scene: 1 })
    // dev 演示：验证码自动回填输入框
    if (data.data.mock_code) {
      sms.code = data.data.mock_code
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

async function smsLogin() {
  loading.value = true
  try {
    const { data } = await authApi.smsLogin({
      country_code: '+86',
      phone: sms.phone,
      code: sms.code,
    })
    await handleLoginResult(data.data)
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}

async function handleLoginResult(d) {
  if (d.need_totp) {
    router.push({ name: 'totp-verify', query: { ticket: d.totp_ticket } })
    return
  }
  if (d.user) {
    store.setUser(d.user)
    ElMessage.success(d.is_new ? '注册成功，欢迎！' : '登录成功')
    router.push(route.query.redirect || '/')
  }
}

function oauth(provider) {
  // 尚未申请第三方 OAuth App 凭据：直接友好提示（docs/05 §3.3；后端门禁双保险）
  ElMessage.info(`${provider === 'github' ? 'GitHub' : 'Google'} 登录暂未开通，敬请期待`)
}
</script>
