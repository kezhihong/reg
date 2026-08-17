<template>
  <AppPage title="账号安全">
    <!-- 目录：点击进入具体设置 -->
    <div class="app-card" style="padding: 6px 14px">
      <div class="app-menu-item" @click="$router.push('/security/totp')">
        <div class="app-menu-icon" style="background: #7a5cff">🔐</div>
        <div class="app-menu-label">
          两步验证
          <div class="app-menu-desc">谷歌身份验证器（Google Authenticator）动态码</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
      <div class="app-menu-item" @click="$router.push('/security/password')">
        <div class="app-menu-icon" style="background: #f5a623">🔑</div>
        <div class="app-menu-label">
          修改密码
          <div class="app-menu-desc">定期更换密码，保护账号安全</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
      <div class="app-menu-item" @click="$router.push('/security/oauth')">
        <div class="app-menu-icon" style="background: #4285f4">🌐</div>
        <div class="app-menu-label">
          第三方账号绑定
          <div class="app-menu-desc">GitHub / Google 快捷登录</div>
        </div>
        <span class="app-menu-arrow">›</span>
      </div>
    </div>
  </AppPage>
</template>

<script setup>
import AppPage from '../components/AppPage.vue'
</script>
