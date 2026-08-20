<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { CalendarDate, DateFormatter, getLocalTimeZone, parseDate } from '@internationalized/date'
import { useEmployeeStore } from '../../stores/employee'
import { usePositionStore } from '../../stores/position'
import { useDepartmentStore } from '../../stores/department'

// 1. Definisikan Schema Validasi
const schema = z.object({
  name: z.string({ message: 'Required' }).min(2, 'Name is too short'),
  employee_code: z.string({ message: 'Required' }).min(1, 'NIK/Code is required'),
  email: z.string({ message: 'Required' }).email('Invalid email'),
  position_id: z.string({ message: 'Required' }),
  department_id: z.string({ message: 'Required' }), // Anggap primary department dulu
  join_date: z.custom<CalendarDate>((v) => v instanceof CalendarDate, 'Join date is required'),
  end_contract_date: z.custom<CalendarDate | null | undefined>((v) => v === undefined || v === null || v instanceof CalendarDate, 'End contract date is invalid').optional(),
  is_active: z.boolean()
})

type Schema = z.output<typeof schema>

  const props = defineProps<{
  // 'modelValue' adalah standar Vue untuk v-model
  modelValue: boolean 
  // Jika ada ID, berarti Edit Mode. Jika null/undefined, berarti Create Mode
  employeeId?: string | null 
  // Opsional: Data awal untuk edit
  initialData?: any 
}>()

const emit = defineEmits(['update:modelValue', 'saved'])

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

const isEditMode = computed(() => !!props.employeeId)

const modalTitle = computed(() => isEditMode.value ? 'Edit Employee' : 'New Employee')
const modalDescription = computed(() => isEditMode.value ? 'Update existing employee data' : 'Create a new employee record')
const buttonLabel = computed(() => isEditMode.value ? 'Save Changes' : 'Create Employee')

const open = ref(false)
const toast = useToast()
const df = new DateFormatter('id-ID', { dateStyle: 'medium' })
const employeeStore = useEmployeeStore()
const positionStore = usePositionStore()
const departmentStore = useDepartmentStore()

// 2. Dummy Data untuk Dropdown (Nanti diganti API dari ReferenceController)
// const positions = [
//   { id: 'uuid-pos-1', label: 'Staff' },
//   { id: 'uuid-pos-2', label: 'Supervisor' },
//   { id: 'uuid-pos-3', label: 'Manager' }
// ]

const positions = computed(() => positionStore.positionOptions) // use formatted options from store for dropdown

// const departments = [
//   { id: 'uuid-dept-1', label: 'IT Department' },
//   { id: 'uuid-dept-2', label: 'HR Department' },
//   { id: 'uuid-dept-3', label: 'Production' }
// ]
const departments = computed(() => departmentStore.departmentOptions)

// 3. Initial State
const state = reactive<Partial<Schema>>({
  name: undefined,
  employee_code: undefined,
  email: undefined,
  position_id: undefined,
  department_id: undefined,
  join_date: undefined,
  end_contract_date: undefined,
  is_active: true // Default active
})

function normalizeDate(value: string): string {
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return value
  }

  const match = value.match(/^(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})$/)
  if (!match) {
    throw new Error(`Unsupported date format: ${value}`)
  }

  const months: Record<string, string> = {
    Jan: '01', Feb: '02', Mar: '03', Apr: '04', May: '05', Jun: '06',
    Jul: '07', Aug: '08', Sep: '09', Oct: '10', Nov: '11', Dec: '12',
    Mei: '05', Agu: '08', Okt: '10', Des: '12' // Indonesian abbreviations
  }
  const month = months[match[2]]
  if (!month) {
    throw new Error(`Unsupported month: ${match[2]}`)
  }

  return `${match[3]}-${month}-${match[1].padStart(2, '0')}`
}

// 4. Submit Handler
async function onSubmit(event: FormSubmitEvent<Schema>) {
  // Disini nanti panggil employeeStore.createEmployee(event.data)
  console.log('Payload:', event.data)

  const payload = {
    ...event.data,
    join_date: event.data.join_date.toString(),
    end_contract_date: event.data.end_contract_date ? event.data.end_contract_date.toString() : null,

    departments: [
      { 
        id: event.data.department_id, 
        is_primary: true 
      }
    ]
  }

  try {
    if (isEditMode.value && props.employeeId) {
      await employeeStore.updateEmployee(props.employeeId, payload)
    } else {
      await employeeStore.createEmployee(payload)
    }
    toast.add({ 
      title: 'Success', 
      description: `Employee ${event.data.name} saved successfully`, 
      color: 'success' 
    })
    employeeStore.fetchEmployees()
    emit('saved')
    isOpen.value = false
  } catch (error) {
    console.error('Error saving employee:', error)
    toast.add({ 
      title: `Error ${error.response?.status}`, 
      description: `Failed to ${isEditMode.value ? 'update' : 'create'} employee: ${error.response?.data?.message}`, 
      color: 'error' 
    })
  }
}

function loadDropdownData() {
  positionStore.fetchPositions()
  departmentStore.fetchDepartments()
}

loadDropdownData()

watch(() => props.modelValue, (isOpen) => {
  if (isOpen) {
    if (isEditMode.value && props.initialData) {
      // Logic isi form untuk Edit
      Object.assign(state, props.initialData)
      if (props.initialData.join_date) {
        state.join_date = parseDate(normalizeDate(props.initialData.join_date))
      }

      if (props.initialData.end_contract_date) {
        state.end_contract_date = parseDate(normalizeDate(props.initialData.end_contract_date))
      }

      if (props.initialData.position) {
        state.position_id = props.initialData.position.id
      }

      if (props.initialData.position) {
        state.department_id = props.initialData.department.id
      }

      // if (props.initialData.departments && props.initialData.departments.length > 0) {
      //   const primaryDept = props.initialData.departments.find((d: any) => d.is_primary)
        
      //   state.department_id = primaryDept ? primaryDept.id : props.initialData.departments[0].id
      // }
    } else {
      // Logic reset form untuk New
      state.name = undefined
      state.email = undefined
      state.employee_code = undefined
      state.position_id = undefined
      state.department_id = undefined
      state.join_date = undefined
      state.end_contract_date = undefined
      state.is_active = true
    }
  }
})
</script>

<template>
  <UModal 
    v-model:open="isOpen" 
    :title="modalTitle"
    :description="modalDescription"
    :ui="{ content: 'sm:max-w-2xl' }" 
  >
    <template #body>
      <UForm
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField label="Employee Code (NIK)" name="employee_code" required>
            <UInput v-model="state.employee_code" placeholder="e.g. EMP-001" class="w-full" />
          </UFormField>
          
          <UFormField label="Full Name" name="name" required>
            <UInput v-model="state.name" placeholder="John Doe" class="w-full" />
          </UFormField>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField label="Email" name="email" required>
            <UInput v-model="state.email" type="email" placeholder="john@company.com" class="w-full" />
          </UFormField>

          <UFormField label="Position" name="position_id" required>
            <USelectMenu 
              v-model="state.position_id"
              :items="positions"
              value-key="id"
              option-attribute="label"
              placeholder="Select Position"
              class="w-full"
            />
          </UFormField>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField label="Department" name="department_id" required>
            <USelectMenu 
              v-model="state.department_id"
              :items="departments"
              value-key="id"
              option-attribute="label"
              placeholder="Select Department"
              class="w-full"
            />
          </UFormField>

          <UFormField label="Status" name="is_active">
            <div class="flex items-center gap-2 mt-1">
              <USwitch v-model="state.is_active" />
              <span class="text-sm text-gray-600 dark:text-gray-400">
                {{ state.is_active ? 'Active Employee' : 'Inactive' }}
              </span>
            </div>
          </UFormField>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField label="Join Date" name="join_date" required>
            <UPopover :ui="{ content: 'p-0' }">
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-calendar"
                class="w-full justify-start text-left font-normal"
                :class="!state.join_date && 'text-gray-500'"
              >
                {{ state.join_date ? df.format(state.join_date.toDate(getLocalTimeZone())) : 'Select date' }}
              </UButton>
              <template #content>
                <UCalendar v-model="state.join_date" class="p-2" />
              </template>
            </UPopover>
          </UFormField>

          <UFormField label="End Contract Date" name="end_contract_date">
            <UPopover :ui="{ content: 'p-0' }">
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-calendar"
                class="w-full justify-start text-left font-normal"
                :class="!state.end_contract_date && 'text-gray-500'"
              >
                {{ state.end_contract_date ? df.format(state.end_contract_date.toDate(getLocalTimeZone())) : 'Select date (optional)' }}
              </UButton>
              <template #content>
                <UCalendar v-model="state.end_contract_date" class="p-2" />
              </template>
            </UPopover>
          </UFormField>
        </div>

        <div class="flex justify-end gap-2 mt-4 pt-4 border-t border-gray-200 dark:border-gray-800">
          <UButton
            label="Cancel"
            color="neutral"
            variant="subtle"
            class="cursor-pointer"
            @click="isOpen = false"
          />
          <UButton
            label="Save Employee"
            color="primary"
            variant="solid"
            type="submit"
            :loading="employeeStore.loading"
            class="cursor-pointer"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>