<template>
  <div class="auth-shell">
    <div class="auth-hero">
      <h1>找回密码 🔑</h1>
      <p>通过手机号或邮箱重置密码</p>
    </div>
    <div class="auth-box">
      <el-steps :active="step" align-center style="margin-bottom: 20px">
        <el-step title="提交账号" />
        <el-step title="重置密码" />
        <el-step title="完成" />
      </el-steps>

      <template v-if="step === 0">
        <el-input v-model="account" placeholder="手机号（含区号，如 +8613800000000）或邮箱" size="large" />
        <button class="app-btn-primary" :disabled="loading" @click="submit">发送重置凭证</button>
        <el-alert
          title="无论账号是否存在，系统都会返回成功（防账号枚举）"
          type="info"
          :closable="false"
          style="margin-top: 14px; border-radius: 10px"
        />
      </template>

      <template v-else-if="step === 1">
        <el-input v-model="code" placeholder="6 位验证码" size="large" style="margin-bottom: 12px" />
        <el-input v-model="newPassword" type="password" placeholder="新密码（8-72 位，含字母与数字）" size="large" show-password />
        <button class="app-btn-primary" :disabled="loading" @click="reset">重置密码</button>
      </template>

      <template v-else>
        <div style="text-align: center; padding: 20px 0">
          <div style="font-size: 48px">✅</div>
          <div style="font-size: 18px; font-weight: 700; margin: 12px 0 4px">密码已重置</div>
          <p class="text-muted">所有设备已下线，请使用新密码重新登录</p>
          <button class="app-btn-primary" @click="$router.push('/login')">去登录</button>
        </div>
      </template>

      <div style="text-align: center; margin-top: 14px">
        <router-link class="app-link" to="/login">返回登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { authApi } from '../api'

const step = ref(0)
const account = ref('')
const code = ref('')
const newPassword = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  try {
    await authApi.forgotPassword({ account: account.value })
    ElMessage.success('重置凭证已发送')
    step.value = 1
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}

async function reset() {
  loading.value = true
  try {
    await authApi.resetPassword({ account: account.value, code: code.value, new_password: newPassword.value })
    step.value = 2
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>
