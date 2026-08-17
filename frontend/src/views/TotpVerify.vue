<template>
  <div class="auth-shell">
    <div class="auth-hero">
      <h1>两步验证 🔐</h1>
      <p>请输入 Google Authenticator 动态码或恢复码</p>
    </div>
    <div class="auth-box">
      <el-tabs v-model="tab" stretch>
        <el-tab-pane label="动态码" name="totp">
          <el-input v-model="code" placeholder="6 位动态码" size="large" maxlength="6" style="margin-bottom: 12px" @keyup.enter="verifyTotp" />
          <button class="app-btn-primary" :disabled="loading" @click="verifyTotp">验 证</button>
        </el-tab-pane>
        <el-tab-pane label="恢复码" name="recovery">
          <el-input v-model="recoveryCode" placeholder="8 位恢复码" size="large" style="margin-bottom: 12px" @keyup.enter="verifyRecovery" />
          <button class="app-btn-primary" :disabled="loading" @click="verifyRecovery">验 证</button>
        </el-tab-pane>
      </el-tabs>
      <div style="text-align: center; margin-top: 16px">
        <router-link class="app-link" to="/login">返回登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { totpApi } from '../api'
import { useUserStore } from '../stores/user'

const route = useRoute()
const router = useRouter()
const store = useUserStore()

const tab = ref('totp')
const code = ref('')
const recoveryCode = ref('')
const loading = ref(false)
const ticket = ref(route.query.ticket || '')

onMounted(() => {
  if (!ticket.value) {
    ElMessage.warning('缺少登录票据，请重新登录')
    router.push('/login')
  }
})

async function handleSuccess(d) {
  if (d.user) {
    store.setUser(d.user)
    ElMessage.success('验证通过')
    router.push(route.query.redirect || '/')
  }
}

async function verifyTotp() {
  loading.value = true
  try {
    const { data } = await totpApi.loginVerify({ totp_ticket: ticket.value, code: code.value })
    await handleSuccess(data.data)
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}

async function verifyRecovery() {
  loading.value = true
  try {
    const { data } = await totpApi.recoveryVerify({ totp_ticket: ticket.value, recovery_code: recoveryCode.value })
    await handleSuccess(data.data)
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>
