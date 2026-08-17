<template>
  <AppPage title="两步验证">
    <!-- 未启用 -->
    <div class="app-card" v-if="!totpEnabled">
      <div class="app-card-title">谷歌身份验证器（Google Authenticator）</div>
      <p class="text-muted" style="margin: 0 0 4px">
        开启后登录需输入验证器中的 6 位动态码，账号更安全。
      </p>
      <p class="text-muted" style="margin: 0 0 12px">
        手机安装 Google Authenticator（或同类 TOTP 应用）后扫码 / 输入 Secret 添加账号。
      </p>
      <button class="app-btn-primary" style="margin-top: 0" @click="startTotp">开启两步验证</button>
    </div>

    <!-- 已启用 -->
    <div class="app-card" v-else>
      <div style="display: flex; align-items: center; justify-content: space-between">
        <div>
          <div style="font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 6px">
            ✅ 已开启
          </div>
          <div class="text-muted" style="margin-top: 4px">登录时需输入谷歌身份验证器动态码</div>
        </div>
        <div style="display: flex; gap: 8px">
          <el-button size="small" round @click="showRecoveryCodes">恢复码</el-button>
          <el-button size="small" round type="danger" plain @click="disableTotp">关闭</el-button>
        </div>
      </div>
    </div>

    <!-- 启用弹窗：扫码添加（不展示 Secret 明文） -->
    <el-dialog v-model="totpDialog" title="开启两步验证" width="92%" style="max-width: 400px">
      <el-alert title="使用 Google Authenticator 扫描下方二维码添加账号" type="info" :closable="false" />
      <div style="text-align: center; margin: 16px 0">
        <div v-if="qrDataUrl" style="display: inline-block; padding: 10px; background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.08)">
          <img :src="qrDataUrl" alt="2FA 二维码" style="width: 210px; height: 210px; display: block" />
        </div>
        <div v-else style="width: 210px; height: 210px; margin: 0 auto; display: flex; align-items: center; justify-content: center" class="text-muted">
          二维码生成中…
        </div>
      </div>
      <el-input v-model="totpCode" placeholder="输入验证器中的 6 位动态码" maxlength="6" size="large" />
      <template #footer>
        <el-button @click="totpDialog = false">取消</el-button>
        <el-button type="primary" :loading="enabling" @click="verifyEnable">启用</el-button>
      </template>
    </el-dialog>

    <!-- 恢复码 -->
    <el-dialog v-model="codesDialog" title="恢复码" width="92%" style="max-width: 400px">
      <el-alert title="每个恢复码仅可使用一次；请妥善保存，2FA 关闭后全部作废" type="warning" :closable="false" />
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 14px 0">
        <el-tag v-for="(c, i) in recoveryCodes" :key="i" size="large">{{ c }}</el-tag>
      </div>
      <el-alert v-if="recoverySummary" :title="`共 ${recoverySummary.total} 个，剩余 ${recoverySummary.remaining} 个`" type="info" :closable="false" />
      <template #footer>
        <el-button type="primary" @click="codesDialog = false">我已保存</el-button>
      </template>
    </el-dialog>
  </AppPage>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import QRCode from 'qrcode'
import AppPage from '../components/AppPage.vue'
import { totpApi, userApi } from '../api'
import { useUserStore } from '../stores/user'

const router = useRouter()
const store = useUserStore()

const totpEnabled = ref(false)
const enabling = ref(false)
const totpDialog = ref(false)
const qrDataUrl = ref('')
const totpCode = ref('')
// Secret 仅保存在内存用于启用校验，界面不展示明文（docs/01 D7）
let totpSecret = ''

const codesDialog = ref(false)
const recoveryCodes = ref([])
const recoverySummary = ref(null)

onMounted(async () => {
  try {
    const { data } = await userApi.me()
    totpEnabled.value = data.data.totp_enabled
  } catch (e) { /* 已提示 */ }
})

async function startTotp() {
  const { data } = await totpApi.enableStart()
  totpSecret = data.data.secret
  qrDataUrl.value = await QRCode.toDataURL(data.data.otpauth_uri, {
    width: 220,
    margin: 1,
    errorCorrectionLevel: 'M',
  })
  totpDialog.value = true
}

async function verifyEnable() {
  enabling.value = true
  try {
    const { data } = await totpApi.enableVerify({ secret: totpSecret, code: totpCode.value })
    totpEnabled.value = true
    totpDialog.value = false
    recoveryCodes.value = data.data.recovery_codes
    codesDialog.value = true
    ElMessage.success('两步验证已启用，请保存恢复码')
  } catch (e) { /* 已提示 */ } finally {
    enabling.value = false
  }
}

async function showRecoveryCodes() {
  const { data } = await totpApi.recoveryCodes()
  if (data.data.codes) {
    recoveryCodes.value = data.data.codes
    recoverySummary.value = null
  } else {
    recoveryCodes.value = []
    recoverySummary.value = { total: data.data.total, remaining: data.data.remaining }
  }
  codesDialog.value = true
}

async function disableTotp() {
  const { value } = await ElMessageBox.prompt('请输入当前 6 位动态码（或恢复码）', '关闭两步验证', {
    inputPlaceholder: '动态码或恢复码',
    confirmButtonText: '确认关闭',
  })
  await totpApi.disable({ code: value })
  store.reset()
  ElMessage.success('两步验证已关闭，请重新登录')
  router.push('/login')
}
</script>
