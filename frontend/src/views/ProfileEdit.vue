<template>
  <AppPage title="修改资料">
    <div class="app-card">
      <div class="app-card-title">头像（点击上传图片）</div>
      <div style="text-align: center; margin-bottom: 14px">
        <el-upload
          :show-file-list="false"
          :http-request="uploadAvatar"
          accept="image/jpeg,image/png,image/webp,image/gif"
          :before-upload="beforeUpload"
        >
          <div class="avatar-uploader">
            <img v-if="form.avatar_url" :src="form.avatar_url" class="avatar-img" />
            <div v-else class="avatar-char">{{ initial }}</div>
            <div class="avatar-edit-badge">📷</div>
          </div>
        </el-upload>
        <div class="text-muted" style="margin-top: 8px">支持 jpg / png / webp / gif，不超过 2MB</div>
      </div>

      <div class="app-card-title">昵称</div>
      <el-input v-model="form.nickname" placeholder="昵称（1-32 字符）" maxlength="32" size="large" style="margin-bottom: 12px" />
      <button class="app-btn-primary" :disabled="loading" @click="save">保存</button>
    </div>
  </AppPage>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import AppPage from '../components/AppPage.vue'
import { userApi } from '../api'

const me = ref({})
const loading = ref(false)
const uploading = ref(false)
const form = reactive({ nickname: '', avatar_url: '' })

const initial = computed(() => (form.nickname || me.value.username || '?').charAt(0).toUpperCase())

onMounted(async () => {
  const { data } = await userApi.me()
  me.value = data.data
  form.nickname = data.data.nickname || ''
  form.avatar_url = data.data.avatar_url || ''
})

function beforeUpload(file) {
  const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  if (!allowed.includes(file.type)) {
    ElMessage.error('不支持的图片格式（jpg/png/webp/gif）')
    return false
  }
  if (file.size > 2 * 1024 * 1024) {
    ElMessage.error('头像大小不能超过 2MB')
    return false
  }
  return true
}

async function uploadAvatar(option) {
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('avatar', option.file)
    const { data } = await userApi.uploadAvatar(fd)
    form.avatar_url = data.data.avatar_url
    ElMessage.success('头像已上传')
  } catch (e) { /* 拦截器已提示 */ } finally {
    uploading.value = false
  }
}

async function save() {
  loading.value = true
  try {
    const { data } = await userApi.updateProfile(form)
    me.value = data.data
    ElMessage.success('已保存')
  } catch (e) { /* 已提示 */ } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.avatar-uploader {
  position: relative;
  width: 80px;
  height: 80px;
  margin: 0 auto;
  cursor: pointer;
  border-radius: 50%;
  overflow: visible;
}
.avatar-img {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
  display: block;
}
.avatar-char {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: linear-gradient(135deg, #4f7cff, #7a5cff);
  color: #fff;
  font-size: 34px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 3px solid #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}
.avatar-edit-badge {
  position: absolute;
  right: -4px;
  bottom: -4px;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #4f7cff;
  color: #fff;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
}
</style>
