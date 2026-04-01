<script setup lang="ts">
import { useTemplateRef, h, ref, watch, resolveComponent, computed } from 'vue'
import { useRouter } from 'vue-router';
import {
  CalendarDate,
  DateFormatter,
  getLocalTimeZone,
  parseDate,
} from "@internationalized/date";
import { titleCase, upperFirst } from 'scule'
import type { TableColumn } from '@nuxt/ui'
import { useFetch } from '@vueuse/core'
// import { getPaginationRowModel, type Row } from '@tanstack/table-core'
import type { User } from '../types'
import type { Department } from '../stores/department'
import { searchForWorkspaceRoot } from 'vite';
import { useEmployeeStore } from '../stores/employee'
import { usePeriodStore } from '../stores/period';
import { useAuthStore } from '../stores/auth';
import { api } from '../services/api';

const employeeStore = useEmployeeStore()
const periodStore = usePeriodStore()
const authStore = useAuthStore()
const UAvatar = resolveComponent('UAvatar')
const UButton = resolveComponent('UButton')
const UBadge = resolveComponent('UBadge')
const UCard = resolveComponent('UCard')
const UDropdownMenu = resolveComponent('UDropdownMenu')
const UCheckbox = resolveComponent('UCheckbox')

const toast = useToast()
const table = useTemplateRef('table')
const router = useRouter()
const page = ref(1)
const pageCount = ref(10)
const sorting = ref([{ id: 'name', desc: false }])
const search = ref('')
const formatDate = new DateFormatter("id-ID", { dateStyle: "medium" });
const userRole = authStore.user?.role || 'guest'

const showModal = ref(false)
const selectedEmployeeId = ref<string | null>(null)
const selectedEmployeeData = ref(null)
const selectedPeriodId = ref<string | undefined>(null)
const canEdit = computed(() => ['admin', 'hr_manager'].includes(authStore.user?.role))

const showEvaluationModal = ref(false)
const evaluationData = ref(null)

const periods = computed(() => periodStore.periodOptions)
const employees = computed(() => employeeStore.employees.map(e => ({
  ...e,
  join_date: e.join_date ? formatDate.format(new Date(e.join_date)) : null
})))

function openCreateModal() {
  selectedEmployeeId.value = null
  selectedEmployeeData.value = null
  showModal.value = true
}

function openEditModal(employee: any) {
  selectedEmployeeId.value = employee.id
  selectedEmployeeData.value = employee
  console.log('Employee Data:', employee)
  showModal.value = true
}

const columnFilters = ref([{
  id: 'name',
  value: ''
}])
const columnVisibility = ref()
const rowSelection = ref({})

// const { data, isFetching } = useFetch('https://dashboard-template.nuxt.dev/api/customers', { initialData: [] }).json<User[]>()
const { data, isFetching } = useFetch('https://dashboard-template.nuxt.dev/api/customers', { initialData: [] }).json<User[]>()

function getRowItems(row: Row<User>) {
  // 1. Definisikan item yang SELALU muncul (Basic)
  const items: any[] = [
    {
      type: 'label',
      label: 'Actions'
    }
  ]

  // 2. Kondisi Khusus: DELETE (Misalnya hanya untuk 'admin')
  if (userRole === 'admin' || userRole === 'hr') {
    items.push({
      label: 'Edit',
      icon: 'i-lucide-edit-2',
      class: 'cursor-pointer',
      onSelect() {
        try {
          openEditModal(row.original)
        } catch (error) {
          console.error('Error editing employee:', error)
        }
      }
    },{
      label: 'Delete',
      icon: 'i-lucide-trash',
      class: 'cursor-pointer',
      color: 'error',
      onSelect() {
        try {
          employeeStore.deleteEmployee(row.original.id)
          toast.add({
            title: 'Employee deleted',
            description: 'The employee has been deleted.'
          })
        } catch (error) {
          console.error('Error deleting employee:', error)
        }
      }
    },{
      label: 'Status Evaluation All',
      icon: 'i-lucide-info',
      class: 'cursor-pointer',
      onSelect() {
        selectedEmployeeId.value = row.original.id
        
        if (!selectedPeriodId.value) {
          toast.add({
            title: 'Select Period',
            description: 'Please select a period before evaluating.',
            color: 'warning'
          })
          return
        }

        // Fetch evaluation status
        api.get(`/employees/${row.original.id}/hr-evaluation-status?period_id=${selectedPeriodId.value}`)
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
    })
  }

  // 3. Kondisi Khusus: EVALUATE (Misalnya untuk 'manager' atau 'hr')
  // Kita gunakan .includes jika role yang diizinkan lebih dari satu
  if (['hr', 'hr2', 'employee'].includes(userRole)) {
    // Tambahkan separator dulu agar rapi
    items.push({ type: 'separator' })
    
    items.push({
      label: 'Evaluate',
      icon: 'i-lucide-clipboard-pen',
      class: 'cursor-pointer',
      onSelect() {
        selectedEmployeeId.value = row.original.id
        
        // Cek Periode (Logic Anda)
        if (!selectedPeriodId.value) {
          toast.add({
            title: 'Select Period',
            description: 'Please select a period before evaluating.',
            color: 'warning'
          })
          return
        }

        router.push({
          path: '/form',
          query: {
            employeeId: selectedEmployeeId.value,
            periodId: selectedPeriodId.value
          }
        })
      }
    })

    // items.push({
    //   label: 'Delete Evaluation',
    //   icon: 'i-lucide-trash',
    //   class: 'cursor-pointer',
    //   color: 'error',
    //   onSelect() {
    //     selectedEmployeeId.value = row.original.current_evaluation;
    //     api.delete(`/evaluations/${selectedEmployeeId.value}`)
    //       .then(() => {
    //         toast.add({
    //           title: 'Evaluation deleted',
    //           description: 'The evaluation has been deleted.'
    //         });
    //         loadData(); // Refresh data setelah penghapusan
    //       })
    //       .catch((error) => {
    //         console.error('Error deleting evaluation:', error);
    //         toast.add({
    //           title: 'Error',
    //           description: 'Failed to delete evaluation.',
    //           color: 'error'
    //         });
    //       });
    //   }
    // })
  }

  // 4. Kembalikan array final
  return items
}

const columns: TableColumn<Department>[] = [
  // {
  //   id: 'select',
  //   header: ({ table }) =>
  //     h(UCheckbox, {
  //       'modelValue': table.getIsSomePageRowsSelected()
  //         ? 'indeterminate'
  //         : table.getIsAllPageRowsSelected(),
  //       'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
  //         table.toggleAllPageRowsSelected(!!value),
  //       'ariaLabel': 'Select all',
  //       'class': 'cursor-pointer',
  //       'ui': { 
  //         base: 'cursor-pointer', 
  //         wrapper: 'items-center cursor-pointer',
  //         container: 'cursor-pointer'
  //       }
  //     }),
  //   cell: ({ row }) =>
  //     h(UCheckbox, {
  //       'modelValue': row.getIsSelected(),
  //       'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
  //       'ariaLabel': 'Select row',
  //       'class': 'cursor-pointer',
  //       'ui': { 
  //         base: 'cursor-pointer', 
  //         wrapper: 'items-center cursor-pointer',
  //         container: 'cursor-pointer'
  //       }
  //     })
  // },
  // {
  //   accessorKey: 'id',
  //   header: 'ID'
  // },
  // {
  //   accessorKey: 'employee_code',
  //   header: ({ column }) => {
  //     const isSorted = column.getIsSorted()

  //     return h(UButton, {
  //       color: 'neutral',
  //       variant: 'ghost',
  //       label: 'Employee Code',
  //       icon: isSorted
  //         ? isSorted === 'asc'
  //           ? 'i-lucide-arrow-up-narrow-wide'
  //           : 'i-lucide-arrow-down-wide-narrow'
  //         : 'i-lucide-arrow-up-down',
  //       class: '-mx-2.5 cursor-pointer',
  //       onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
  //     })
  //   }
  // },
  {
    accessorKey: 'name',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Name',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5 cursor-pointer',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => {
      return h('div', { class: 'flex items-center gap-3' }, [
        // h(UAvatar, {
        //   ...row.original.avatar,
        //   size: 'lg'
        // }),
        h('div', undefined, [
          h('p', { class: 'font-medium text-highlighted' }, row.original.name),
          // h('p', { class: '' }, `@${row.original.name}`)
        ])
      ])
    }
  },
  // {
  //   accessorKey: 'email',
  //   header: ({ column }) => {
  //     const isSorted = column.getIsSorted()

  //     return h(UButton, {
  //       color: 'neutral',
  //       variant: 'ghost',
  //       label: 'Email',
  //       icon: isSorted
  //         ? isSorted === 'asc'
  //           ? 'i-lucide-arrow-up-narrow-wide'
  //           : 'i-lucide-arrow-down-wide-narrow'
  //         : 'i-lucide-arrow-up-down',
  //       class: '-mx-2.5 cursor-pointer',
  //       onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
  //     })
  //   },
  // },
  {
    accessorKey: 'position',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Position',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5 cursor-pointer',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
    cell: ({ row }) => row.original.position?.title
  },
  // {
  //   accessorKey: 'status',
  //   header: 'Status',
  //   filterFn: 'equals',
  //   cell: ({ row }) => {
  //     const color = {
  //       subscribed: 'success' as const,
  //       unsubscribed: 'error' as const,
  //       bounced: 'warning' as const
  //     }[row.original.status]

  //     return h(UBadge, { class: 'capitalize', variant: 'subtle', color }, () =>
  //       row.original.status
  //     )
  //   }
  // },
  {
    accessorKey: 'join_date',
    header: ({ column }) => {
      const isSorted = column.getIsSorted()

      return h(UButton, {
        color: 'neutral',
        variant: 'ghost',
        label: 'Join Date',
        icon: isSorted
          ? isSorted === 'asc'
            ? 'i-lucide-arrow-up-narrow-wide'
            : 'i-lucide-arrow-down-wide-narrow'
          : 'i-lucide-arrow-up-down',
        class: '-mx-2.5 cursor-pointer',
        onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
      })
    },
  },
  // {
  //   accessorKey: 'status',
  //   header: ({ column }) => {
  //     return h(UButton, {
  //       color: 'neutral',
  //       variant: 'ghost',
  //       label: 'Status',
  //     })
  //   },
  // },
  {
    accessorKey: "status",
    header: "Status",
    cell: ({ row }) => {
      return h(
        UBadge,
        {
          variant: "subtle",
          color: row.original.current_evaluation ? "success" : "neutral",
          class: "capitalize",
        },
        () => (row.original.current_evaluation ? "Done" : "Pending")
      );
    },
  },
  // {
  //   accessorKey: 'end_contract_date',
  //   header: ({ column }) => {
  //     const isSorted = column.getIsSorted()

  //     return h(UButton, {
  //       color: 'neutral',
  //       variant: 'ghost',
  //       label: 'End Contract',
  //       icon: isSorted
  //         ? isSorted === 'asc'
  //           ? 'i-lucide-arrow-up-narrow-wide'
  //           : 'i-lucide-arrow-down-wide-narrow'
  //         : 'i-lucide-arrow-up-down',
  //       class: '-mx-2.5 cursor-pointer',
  //       onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
  //     })
  //   },
  // },
  {
    id: 'actions',
    cell: ({ row }) => {
      return h(
        'div',
        { class: 'text-right cursor-pointer' },
        h(
          UDropdownMenu,
          {
            content: {
              align: 'end'
            },
            items: getRowItems(row)
          },
          () =>
            h(UButton, {
              icon: 'i-lucide-ellipsis-vertical',
              color: 'neutral',
              variant: 'ghost',
              class: 'ml-auto cursor-pointer'
            })
        )
      )
    }
  }
]

const statusFilter = ref('all')

watch(() => statusFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return

  const statusColumn = table.value.tableApi.getColumn('status')
  if (!statusColumn) return

  if (newVal === 'all') {
    statusColumn.setFilterValue(undefined)
  } else {
    statusColumn.setFilterValue(newVal)
  }
})

const pagination = ref({
  pageIndex: 0,
  pageSize: 10
})

async function loadData() {
  const sort = sorting.value[0]

  await periodStore.fetchPeriods()
  selectedPeriodId.value = periodStore.periodOptions[0]?.id || undefined
    
  await employeeStore.fetchEmployees({
    page: page.value,
    per_page: pageCount.value,
    sort_by: sort ? sort.id : 'name',
    sort_direction: sort?.desc ? 'desc' : 'asc',
    search: search.value,
    role: authStore.user?.role || null,
    period_id: selectedPeriodId.value || null
  })
}

function onPageChange(newPage: number) {
  page.value = newPage
  loadData()
}

loadData()

watch(sorting, () => {
  page.value = 1 
  loadData()
})
</script>

<template>
  <UDashboardPanel id="employes">
    
    <template #header>
      <UDashboardNavbar title="Employees">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton v-if="userRole === 'admin' || userRole === 'hr'" label="New Employee" icon="i-lucide-plus" class="cursor-pointer" @click="openCreateModal" />
          <UsersAddModal v-model="showModal" :employeeId="selectedEmployeeId" :initialData="selectedEmployeeData" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Filter name..."
          @change="loadData"
        />

        <USelectMenu 
          v-model="selectedPeriodId"
          :items="periods"
          value-key="id"
          placeholder="Pilih periode"
        />

        <div class="flex flex-wrap items-center gap-1.5">
          <UsersDeleteModal :count="table?.tableApi?.getFilteredSelectedRowModel().rows.length">
            <UButton
              v-if="table?.tableApi?.getFilteredSelectedRowModel().rows.length"
              label="Delete"
              color="error"
              variant="subtle"
              icon="i-lucide-trash"
              class="cursor-pointer"
            >
              <template #trailing>
                <UKbd>
                  {{ table?.tableApi?.getFilteredSelectedRowModel().rows.length }}
                </UKbd>
              </template>
            </UButton>
          </UsersDeleteModal>

          <!-- <USelect
            v-model="statusFilter"
            :items="[
              { label: 'All', value: 'all' },
              { label: 'Subscribed', value: 'subscribed' },
              { label: 'Unsubscribed', value: 'unsubscribed' },
              { label: 'Bounced', value: 'bounced' }
            ]"
            :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            placeholder="Filter status"
            class="min-w-28"
          /> -->
          <UDropdownMenu
            :items="
              table?.tableApi
                ?.getAllColumns()
                .filter((column: any) => column.getCanHide())
                .map((column: any) => ({
                  label: titleCase(column.id),
                  class: 'cursor-pointer',
                  type: 'checkbox' as const,
                  checked: column.getIsVisible(),
                  onUpdateChecked(checked: boolean) {
                    table?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
                  },
                  onSelect(e?: Event) {
                    e?.preventDefault()
                  }
                }))
            "
            :content="{ align: 'end' }"
          >
            <UButton
              label="Display"
              color="neutral"
              class="cursor-pointer"
              variant="outline"
              trailing-icon="i-lucide-settings-2"
            />
          </UDropdownMenu>
        </div>
      </div>

      <UTable
        ref="table"
        v-model:column-filters="columnFilters"
        v-model:column-visibility="columnVisibility"
        v-model:row-selection="rowSelection"
        v-model:sorting="sorting"
        class="shrink-0"
        :data="employees"
        :columns="columns"
        :loading="employeeStore.loading"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default'
        }"
      />

      <!-- <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ table?.tableApi?.getFilteredSelectedRowModel().rows.length || 0 }} of
          {{ table?.tableApi?.getFilteredRowModel().rows.length || 0 }} row(s) selected.
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            :default-page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
            :items-per-page="table?.tableApi?.getState().pagination.pageSize"
            :total="table?.tableApi?.getFilteredRowModel().rows.length"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </div> -->

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          Showing {{ (page - 1) * pageCount + 1 }} to {{ Math.min(page * pageCount, employeeStore.pagination.total) }} of {{ employeeStore.pagination.total }} results
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            v-model="page"
            :page-count="pageCount"
            :total="employeeStore.pagination.total"
            class="[&_button]:!cursor-pointer [&_button]:!pointer-events-auto"
            @update:page="onPageChange"
          />
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
