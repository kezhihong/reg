<template>
  <div class="app-page">
    <div class="app-navbar">
      <span class="nav-back" @click="$router.back()">‹</span>
      <span class="nav-title">{{ title }}</span>
      <span class="nav-placeholder"></span>
    </div>
    <div class="app-content">
      <slot />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
})
</script>
