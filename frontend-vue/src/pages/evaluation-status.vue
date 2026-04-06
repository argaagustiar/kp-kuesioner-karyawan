<script setup lang="ts">
import { ref, computed, resolveComponent, h } from 'vue'
import { useRouter } from 'vue-router'
import { usePeriodStore } from '../stores/period'
import { useAuthStore } from '../stores/auth'
import { api } from '../services/api'

const router = useRouter()
const periodStore = usePeriodStore()
const authStore = useAuthStore()

const UAvatar = resolveComponent('UAvatar')
const UBadge = resolveComponent('UBadge')

const toast = useToast()

const selectedPeriodId = ref<string | undefined>(undefined)
const allEvaluationData = ref([])
const loading = ref(false)
const showEvaluationModal = ref(false)
const evaluationData = ref(null)

const periods = computed(() => periodStore.periodOptions)

const stats = computed(() => {
  const total = allEvaluationData.value.length
  const fully = allEvaluationData.value.filter(e => e.evaluation_summary.overall_status === 'fully_evaluated').length
  const partial = allEvaluationData.value.filter(e => e.evaluation_summary.overall_status === 'partially_evaluated').length
  const pending = total - fully - partial
  return { total, fully, partial, pending }
})

async function fetchData() {
  if (!selectedPeriodId.value) return

  loading.value = true
  try {
    const response = await api.get(`/employees/hr-evaluation-status-all?period_id=${selectedPeriodId.value}`)
    allEvaluationData.value = response.data.data
  } catch (error) {
    console.error('Error fetching evaluation status:', error)
    toast.add({
      title: 'Error',
      description: 'Failed to fetch evaluation status.',
      color: 'error'
    })
  } finally {
    loading.value = false
  }
}

function statusColor(status: string) {
  return status === 'fully_evaluated' ? 'success'
       : status === 'partially_evaluated' ? 'warning'
       : 'neutral'
}

function statusLabel(status: string) {
  return status === 'fully_evaluated' ? 'Fully Evaluated'
       : status === 'partially_evaluated' ? 'Partially Evaluated'
       : 'Pending'
}

async function init() {
  await periodStore.fetchPeriods()
  selectedPeriodId.value = periodStore.periodOptions[0]?.id || undefined
  await fetchData()
}

function detailStatusEmployee(employeeId: string) {
  if (!selectedPeriodId.value) {
    toast.add({
      title: 'Select Period',
      description: 'Please select a period before evaluating.',
      color: 'warning'
    })
    return
  }

  // Fetch evaluation status
  api.get(`/employees/${employeeId}/hr-evaluation-status?period_id=${selectedPeriodId.value}`)
    .then(response => {
      evaluationData.value = response.data.data
      showEvaluationModal.value = true
    })
    .catch(error => {
      console.error('Error fetching evaluation status:', error)
      toast.add({
        title: 'Error',
        description: 'Failed to fetch evaluation status.',
        color: 'error'
      })
    })
}

init()
</script>

<template>
  <UDashboardPanel id="evaluation-status">
    <template #header>
      <UDashboardNavbar title="All Evaluation Status">
        <template #leading>
          <div class="flex items-center gap-2">
            <UDashboardSidebarCollapse />
            <UButton
              icon="i-lucide-arrow-left"
              color="neutral"
              variant="ghost"
              class="cursor-pointer"
              @click="router.back()"
            />
          </div>
        </template>

        <template #right>
          <USelectMenu
            v-model="selectedPeriodId"
            :items="periods"
            value-key="id"
            placeholder="Pilih periode"
            @update:model-value="fetchData"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Stats bar -->
      <div class="flex flex-wrap gap-3 mb-4">
        <UBadge color="neutral" variant="subtle" size="lg">
          Total: {{ stats.total }} employees
        </UBadge>
        <UBadge color="success" variant="subtle" size="lg">
          ✓ {{ stats.fully }} Fully Evaluated
        </UBadge>
        <UBadge color="warning" variant="subtle" size="lg">
          ◑ {{ stats.partial }} Partially Evaluated
        </UBadge>
        <UBadge color="neutral" variant="outline" size="lg">
          ○ {{ stats.pending }} Pending
        </UBadge>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-16 text-muted">
        Loading evaluation data...
      </div>

      <!-- Empty -->
      <div v-else-if="allEvaluationData.length === 0" class="text-center py-16 text-muted">
        No evaluation data found for this period.
      </div>

      <!-- Table -->
      <div v-else class="space-y-2">
        <!-- Header -->
        <div class="grid grid-cols-12 gap-4 px-4 py-2 rounded-lg bg-elevated/50 text-sm font-medium text-muted">
          <div class="col-span-4">Employee</div>
          <div class="col-span-2">Department</div>
          <div class="col-span-3">Progress</div>
          <div class="col-span-3">Status</div>
        </div>

        <!-- Rows -->
        <div
          v-for="employee in allEvaluationData"
          :key="employee.id"
          class="cursor-pointer grid grid-cols-12 gap-4 px-4 py-3 rounded-lg bg-elevated/30 border border-default items-center hover:bg-elevated/60 transition-colors"
          @click="detailStatusEmployee(employee.id)"
        >
          <!-- Employee info -->
          <div class="col-span-4 flex items-center gap-3">
            <UAvatar :name="employee.name" size="sm" />
            <div class="min-w-0">
              <p class="font-medium text-highlighted truncate">{{ employee.name }}</p>
              <p class="text-xs text-muted truncate">{{ employee.position?.title }}</p>
            </div>
          </div>

          <!-- Department -->
          <div class="col-span-2 text-sm text-muted truncate">
            {{ employee.department?.name || '—' }}
          </div>

          <!-- Progress bar -->
          <div class="col-span-3">
            <div class="flex items-center gap-2">
              <div class="flex-1 h-2 rounded-full bg-default overflow-hidden">
                <div
                  class="h-full rounded-full transition-all"
                  :class="{
                    'bg-green-500': employee.evaluation_summary.overall_status === 'fully_evaluated',
                    'bg-amber-500': employee.evaluation_summary.overall_status === 'partially_evaluated',
                    'bg-muted': employee.evaluation_summary.overall_status === 'not_evaluated'
                  }"
                  :style="{
                    width: employee.evaluation_summary.total_evaluators > 0
                      ? `${(employee.evaluation_summary.evaluated_count / employee.evaluation_summary.total_evaluators) * 100}%`
                      : '0%'
                  }"
                />
              </div>
              <span class="text-xs text-muted whitespace-nowrap">
                {{ employee.evaluation_summary.evaluated_count }}/{{ employee.evaluation_summary.total_evaluators }}
              </span>
            </div>
          </div>

          <!-- Status badge -->
          <div class="col-span-3">
            <UBadge
              :color="statusColor(employee.evaluation_summary.overall_status)"
              variant="subtle"
              class="capitalize"
            >
              {{ statusLabel(employee.evaluation_summary.overall_status) }}
            </UBadge>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
  <UModal v-model:open="showEvaluationModal" 
    :title="'Evaluation Status for ' + (evaluationData?.name || '')">
    <template #body>
      <div v-if="evaluationData" class="space-y-4">
        <!-- <div class="text-sm text-muted mb-4">
          Period: {{ evaluationData.period?.name || 'N/A' }}
        </div> -->
        
        <div class="grid gap-3">
          <UCard v-for="evaluator in evaluationData.evaluators" :key="evaluator.id" class="p-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <UAvatar :name="evaluator.name" size="sm" />
                <div>
                  <p class="font-medium">{{ evaluator.name }}</p>
                </div>
              </div>
              <UBadge 
                :color="evaluator.evaluation_status === 'evaluated' ? 'success' : 'warning'" 
                variant="subtle"
                class="capitalize"
              >
                {{ evaluator.evaluation_status === 'evaluated' ? 'Evaluated' : 'Pending' }}
              </UBadge>
            </div>
          </UCard>
        </div>
        
        <div v-if="!evaluationData.evaluators || evaluationData.evaluators.length === 0" class="text-center text-muted py-8">
          No evaluators found for this employee.
        </div>
      </div>
      <div v-else class="text-center py-8">
        Loading evaluation data...
      </div>
    </template>
  </UModal>
</template>