<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from './layouts/AppLayout.vue'
import AuthLayout from './layouts/AuthLayout.vue'
import { useAuthStore } from './stores/auth'

const appConfig = useAppConfig()

appConfig.ui.colors.primary = 'blue'

const route = useRoute()
const authStore = useAuthStore()
// Daftar layout yang tersedia
const layouts = {
  AppLayout,
  AuthLayout
}

const isReady = computed(() => !!route.meta.layout && authStore.isAuthChecked)

const currentLayout = computed(() => {
  return layouts[route.meta.layout as keyof typeof layouts] || AppLayout
})
</script>

<template>
  <UApp>
    <component :is="currentLayout" v-if="isReady" />
  </UApp>
</template>