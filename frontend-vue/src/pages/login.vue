<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ref } from 'vue'

const toast = useToast()
const router = useRouter()
const authStore = useAuthStore()
const errors = ref({})

const fields = [{
  name: 'login',
  type: 'text' as const,
  label: 'Username',
  placeholder: 'Enter your username',
  required: true
}, {
  name: 'password',
  label: 'Password',
  type: 'password' as const,
  placeholder: 'Enter your password'
}]

const schema = z.object({
//   email: z.string().email('Invalid email'),
//   password: z.string().min(8, 'Must be at least 8 characters')
})

type Schema = z.output<typeof schema>

async function onSubmit(payload: FormSubmitEvent<Schema>) {
    errors.value = {}
    try {
    await authStore.login(payload.data)
    toast.add({
        // Diperbaiki
        title: 'Success',
        description: 'Login successful',
        color: 'success',
        })
        router.push('/')
    } catch (error: any) {
        if (error.response?.data?.errors) {
        errors.value = error.response.data.errors
        } else if (error.response?.data?.message) {
        toast.add({
            // Diperbaiki
            title: 'Error',
            description: error.response.data.message,
            color: 'error',
        })
        } else {
        toast.add({
            // Diperbaiki
            title: 'Error',
            description: 'Login failed',
            color: 'error',
        })
        }
    }
}
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-4 p-4">
    <UPageCard class="w-full max-w-md">
      <UAuthForm
        :schema="schema"
        :fields="fields"
        title="Log In"
        icon="i-lucide-user"
        @submit="onSubmit"
      >
        <!-- <template #password-hint>
          <ULink to="#" class="text-primary font-medium" tabindex="-1">Forgot password?</ULink>
        </template> -->
        <!-- <template #validation>
          <UAlert color="error" icon="i-lucide-info" title="Error signing in" />
        </template> -->
        <template #footer>
          For password recovery and account access assistance, please contact the IT Department.
        </template>
      </UAuthForm>
    </UPageCard>
  </div>
</template>

