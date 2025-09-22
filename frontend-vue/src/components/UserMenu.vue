<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import { useColorMode } from '@vueuse/core'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { api } from '../services/api'

defineProps<{
  collapsed?: boolean
}>()

const colorMode = useColorMode()
const appConfig = useAppConfig()
const router = useRouter()
const authStore = useAuthStore()
const userRes = authStore.getMe()
const toast = useToast()

const colors = ['red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose']
const neutrals = ['slate', 'gray', 'zinc', 'neutral', 'stone']

const user = ref({
  name: authStore.user?.username,
  avatar: {
    src: '',
    alt: authStore.user?.username
  }
})

const items = computed<DropdownMenuItem[][]>(() => ([[{
  type: 'label',
  label: user.value.name,
  avatar: user.value.avatar
}]
// , [{
//   label: 'Profile',
//   icon: 'i-lucide-user'
// }, {
//   label: 'Billing',
//   icon: 'i-lucide-credit-card'
// }, {
//   label: 'Settings',
//   icon: 'i-lucide-settings',
//   to: '/settings'
// }], [{
//   label: 'Theme',
//   icon: 'i-lucide-palette',
//   children: [{
//     label: 'Primary',
//     slot: 'chip',
//     chip: appConfig.ui.colors.primary,
//     content: {
//       align: 'center',
//       collisionPadding: 16
//     },
//     children: colors.map(color => ({
//       label: color,
//       chip: color,
//       slot: 'chip',
//       checked: appConfig.ui.colors.primary === color,
//       type: 'checkbox',
//       onSelect: (e) => {
//         e.preventDefault()

//         appConfig.ui.colors.primary = color
//       }
//     }))
//   }, {
//     label: 'Neutral',
//     slot: 'chip',
//     chip: appConfig.ui.colors.neutral === 'neutral' ? 'old-neutral' : appConfig.ui.colors.neutral,
//     content: {
//       align: 'end',
//       collisionPadding: 16
//     },
//     children: neutrals.map(color => ({
//       label: color,
//       chip: color === 'neutral' ? 'old-neutral' : color,
//       slot: 'chip',
//       type: 'checkbox',
//       checked: appConfig.ui.colors.neutral === color,
//       onSelect: (e) => {
//         e.preventDefault()

//         appConfig.ui.colors.neutral = color
//       }
//     }))
//   }]
// }, {
//   label: 'Appearance',
//   icon: 'i-lucide-sun-moon',
//   children: [{
//     label: 'Light',
//     icon: 'i-lucide-sun',
//     type: 'checkbox',
//     checked: colorMode.value === 'light',
//     onSelect(e: Event) {
//       e.preventDefault()

//       colorMode.value = 'light'
//     }
//   }, {
//     label: 'Dark',
//     icon: 'i-lucide-moon',
//     type: 'checkbox',
//     checked: colorMode.value === 'dark',
//     onUpdateChecked(checked: boolean) {
//       if (checked) {
//         colorMode.value = 'dark'
//       }
//     },
//     onSelect(e: Event) {
//       e.preventDefault()
//     }
//   }]
// }], [{
//   label: 'Documentation',
//   icon: 'i-lucide-book-open',
//   to: 'https://ui4.nuxt.com/docs/getting-started/installation/vue',
//   target: '_blank'
// }, {
//   label: 'GitHub repository',
//   icon: 'simple-icons:github',
//   to: 'https://github.com/nuxt-ui-templates/dashboard-vue',
//   target: '_blank'
// }]
, [{
  label: 'Log out',
  icon: 'i-lucide-log-out',
  onSelect: handleLogout
}]]))

async function handleLogout() {
  try {
    await api.post('/logout')
  } catch (error) {
    console.error('Failed to logout from server:', error)
    toast.add({ title: 'Could not log out from server, logging out locally.', color: 'warning' })
  } finally {
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    console.log('Redirecting to /login')
    window.location.href = '/login'
  }
}
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="{
        ...user,
        label: collapsed ? undefined : user?.name,
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    />

    <template #chip-leading="{ item }">
      <span
        :style="{
          '--chip-light': `var(--color-${(item as any).chip}-500)`,
          '--chip-dark': `var(--color-${(item as any).chip}-400)`
        }"
        class="ms-0.5 size-2 rounded-full bg-(--chip-light) dark:bg-(--chip-dark)"
      />
    </template>
  </UDropdownMenu>
</template>
