import './assets/css/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'
import ui from '@nuxt/ui/vue-plugin'
import App from './App.vue'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)

const routes = [
  { path: '/login', component: () => import('./pages/login.vue'), meta: { layout: 'AuthLayout' } },
  { path: '/', component: () => import('./pages/index.vue'), meta: { layout: 'AppLayout', requiresAuth: true } },
  { path: '/inbox', component: () => import('./pages/inbox.vue'), meta: { layout: 'AppLayout', requiresAuth: true } },
  { path: '/customers', component: () => import('./pages/customers.vue'), meta: { layout: 'AppLayout', requiresAuth: true } },
  {
    path: '/settings',
    component: () => import('./pages/settings.vue'),
    children: [
      { path: '', component: () => import('./pages/settings/index.vue') },
      { path: 'members', component: () => import('./pages/settings/members.vue') },
      { path: 'notifications', component: () => import('./pages/settings/notifications.vue') },
      { path: 'security', component: () => import('./pages/settings/security.vue') },
    ],
    meta: { layout: 'AppLayout', requiresAuth: true }
  }
]

const router = createRouter({ routes, history: createWebHistory() })

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()
  if (!authStore.isAuthChecked) {
    await authStore.initAuth()
  }

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next({ path: '/login' })
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next({ path: '/' })
  } else {
    next()
  }
})

app.use(ui)

// *** INISIALISASI AUTH DI SINI, SETELAH PINIA, SEBELUM ROUTER ***
const authStore = useAuthStore()
await authStore.initAuth()

app.use(router)
app.mount('#app')