<template>
  <AppPage title="实名认证">
    <!-- 实名状态 -->
    <div class="app-card" style="text-align: center; padding: 24px 14px">
      <div style="font-size: 44px">{{ me.is_phone_verified ? '✅' : '🛡️' }}</div>
      <div style="font-size: 17px; font-weight: 700; margin-top: 10px">
        {{ me.is_phone_verified ? '已完成实名认证' : '未实名' }}
      </div>
      <div class="text-muted" style="margin-top: 6px">
        {{ me.is_phone_verified ? '已通过手机号验证，账号可信度更高' : '完成手机号验证即可完成实名认证' }}
      </div>
      <div style="display: flex; justify-content: center; gap: 8px; margin-top: 12px">
        <el-tag :type="me.is_phone_verified ? 'success' : 'info'" round>
          {{ me.is_phone_verified ? '已实名' : '未实名' }}
        </el-tag>
        <el-tag v-if="me.phone" type="primary" round>{{ me.phone }}</el-tag>
      </div>
    </div>

    <!-- 实名完成表单（未实名时显示） -->
    <div class="app-card" v-if="!me.is_phone_verified">
      <div class="app-card-title">手机号实名认证</div>
      <p class="text-muted" style="margin: 0 0 10px">
        通过短信验证码验证本人已绑定手机号，完成实名认证。
      </p>
      <el-input v-model="l1.phone" placeholder="手机号" size="large" style="margin-bottom: 10px" />
      <div style="display: flex; gap: 8px">
        <el-input v-model="l1.code" placeholder="6 位验证码" size="large" />
        <el-button size="large" :disabled="countdown > 0" style="width: 120px" @click="sendCode">
          {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
        </el-button>
      </div>
      <button class="app-btn-primary" :disabled="loading" @click="doVerify">完成实名认证</button>
    </div>
  </AppPage>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { kycApi, authApi } from '../api'

const me = ref({})
const loading = ref(false)
const countdown = ref(0)
let timer = null

const l1 = reactive({ phone: '', code: '' })

onMounted(load)
onBeforeUnmount(() => timer && clearInterval(timer))

async function load() {
  try {
    const { data } = await kycApi.status()
    me.value = data.data
  } catch (e) { /* 已提示 */ }
}

async function sendCode() {
  const { data } = await authApi.smsSend({ country_code: '+86', phone: l1.phone, scene: 6 })
  // dev 演示：验证码自动回填输入框
  if (data.data.mock_code) {
    l1.code = data.data.mock_code
    ElMessage.success('验证码已发送并自动填入')
  } else {
    ElMessage.success('验证码已发送')
  }
  countdown.value = 60
  timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) clearInterval(timer)
  }, 1000)
}

async function doVerify() {
  loading.value = true
  try {
    const { data } = await kycApi.l1({ country_code: '+86', phone: l1.phone, code: l1.code })
    me.value = data.data
    ElMessage.success('实名认证完成')
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>
