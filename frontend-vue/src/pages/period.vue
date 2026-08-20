<script setup lang="ts">
import { useTemplateRef, h, ref, watch, resolveComponent, computed } from "vue";
import { useRouter } from "vue-router";
import { DateFormatter } from "@internationalized/date";
import { titleCase } from "scule";
import type { TableColumn } from "@nuxt/ui";
import { usePeriodStore } from "../stores/period"; // Pastikan store ini ada dan sesuai
import { useAuthStore } from "../stores/auth";
import PeriodsAddModal from "../components/periods/PeriodsAddModal.vue";
import AttendanceUploadModal from "../components/periods/AttendanceUploadModal.vue";

// --- STORES ---
const periodStore = usePeriodStore();
const authStore = useAuthStore();

// --- COMPONENTS ---
const UButton = resolveComponent("UButton");
const UBadge = resolveComponent("UBadge");
const UDropdownMenu = resolveComponent("UDropdownMenu");
const UCheckbox = resolveComponent("UCheckbox");

// --- UTILS & REFS ---
const toast = useToast();
const table = useTemplateRef("table");
const router = useRouter();
const formatDate = new DateFormatter("id-ID", { dateStyle: "medium" });

// --- STATE ---
const page = ref(1);
const pageCount = ref(10);
const sorting = ref([{ id: "start_date", desc: true }]); // Default sort by start_date terbaru
const search = ref("");
const userRole = authStore.user?.role || "guest";

// Modal State
const showModal = ref(false);
const showUploadModal = ref(false);
const selectedPeriodId = ref<string | null>(null);
const selectedPeriodData = ref(null);

// --- COMPUTED ---
// Format data periode untuk tampilan tabel
const periods = computed(() => {
  console.log("Computing periods:", periodStore.periods);
  const formatted = periodStore.periods.map((p) => ({
    ...p,
    description: p.description || "-",
    // is_active: p.is_active ?? true,
    start_date_fmt: p.start_date
      ? formatDate.format(new Date(p.start_date))
      : "-",
    end_date_fmt: p.end_date ? formatDate.format(new Date(p.end_date)) : "-",
  }));
  console.log("Formatted periods:", formatted);
  return formatted;
});

// --- ACTIONS ---
function openCreateModal() {
  selectedPeriodId.value = null;
  selectedPeriodData.value = null;
  showModal.value = true;
}

function openEditModal(period: any) {
  selectedPeriodId.value = period.id;
  selectedPeriodData.value = period;
  showModal.value = true;
}

function openUploadModal() {
  showUploadModal.value = true;
}

async function handleDelete(period: any) {
  if (!confirm("Are you sure you want to delete this period?")) return;

  try {
    await periodStore.deletePeriod(period.id);
    toast.add({
      title: "Period deleted",
      description: "The period has been deleted successfully.",
      color: "success",
    });
    loadData();
  } catch (error) {
    console.error("Error deleting period:", error);
    toast.add({
      title: "Error",
      description: "Failed to delete period.",
      color: "error",
    });
  }
}

async function toggleEvaluationLock(period: any) {
  const locked = !period.evaluation_locked

  try {
    await periodStore.updateEvaluationLock(period.id, locked)
    toast.add({
      title: locked ? "Evaluation locked" : "Evaluation unlocked",
      description: locked
        ? "New and existing evaluations can no longer be saved for this period."
        : "Evaluations can be saved again for this period.",
      color: "success",
    })
  } catch (error: any) {
    console.error("Error updating evaluation lock:", error)
    toast.add({
      title: "Error",
      description: error.response?.data?.message || "Failed to update evaluation lock.",
      color: "error",
    })
  }
}

// --- TABLE CONFIG ---
const columnFilters = ref([{ id: "description", value: "" }]);
const columnVisibility = ref();
const rowSelection = ref({});

// Menu Dropdown per Baris
function getRowItems(row: any) {
  const items: any[] = [{ type: "label", label: "Actions" }];

  // Hanya Admin/HR yang bisa edit/delete periode
  if (["admin", "hr", "hr_manager"].includes(userRole)) {
    items.push(
      {
        label: "Attendance",
        icon: "i-lucide-list-check",
        class: "cursor-pointer",
        onSelect: () => {
          router.push(`/attendance?period_id=${row.original.id}`);
        },
      },

      {
        label: "Edit",
        icon: "i-lucide-edit-2",
        class: "cursor-pointer",
        onSelect: () => openEditModal(row.original),
      },
      {
        label: "Delete",
        icon: "i-lucide-trash",
        class: "cursor-pointer",
        color: "error",
        onSelect: () => handleDelete(row.original),
      }
    );
  }

  if (userRole === "admin") {
    items.push({
      label: row.original.evaluation_locked ? "Unlock Evaluation" : "Lock Evaluation",
      icon: row.original.evaluation_locked ? "i-lucide-lock-open" : "i-lucide-lock",
      class: "cursor-pointer",
      onSelect: () => toggleEvaluationLock(row.original),
    });
  }

  return items;
}

// Definisi Kolom Tabel
const columns: TableColumn<any>[] = [
//   {
//     id: "select",
//     header: ({ table }) =>
//       h(UCheckbox, {
//         modelValue: table.getIsSomePageRowsSelected()
//           ? "indeterminate"
//           : table.getIsAllPageRowsSelected(),
//         "onUpdate:modelValue": (val: boolean) =>
//           table.toggleAllPageRowsSelected(!!val),
//         ariaLabel: "Select all",
//         class: "cursor-pointer",
//       }),
//     cell: ({ row }) =>
//       h(UCheckbox, {
//         modelValue: row.getIsSelected(),
//         "onUpdate:modelValue": (val: boolean) => row.toggleSelected(!!val),
//         ariaLabel: "Select row",
//         class: "cursor-pointer",
//       }),
//   },
  {
    accessorKey: "description",
    header: ({ column }) => {
      const isSorted = column.getIsSorted();
      return h(UButton, {
        color: "neutral",
        variant: "ghost",
        label: "Description",
        icon: isSorted
          ? isSorted === "asc"
            ? "i-lucide-arrow-up-narrow-wide"
            : "i-lucide-arrow-down-wide-narrow"
          : "i-lucide-arrow-up-down",
        class: "-mx-2.5 cursor-pointer",
        onClick: () => column.toggleSorting(column.getIsSorted() === "asc"),
      });
    },
    cell: ({ row }) =>
      h(
        "span",
        { class: "font-medium text-highlighted" },
        row.original.description
      ),
  },
  {
    accessorKey: "start_date",
    header: ({ column }) => {
      const isSorted = column.getIsSorted();
      return h(UButton, {
        color: "neutral",
        variant: "ghost",
        label: "Start Date",
        icon: isSorted
          ? isSorted === "asc"
            ? "i-lucide-arrow-up-narrow-wide"
            : "i-lucide-arrow-down-wide-narrow"
          : "i-lucide-arrow-up-down",
        class: "-mx-2.5 cursor-pointer",
        onClick: () => column.toggleSorting(column.getIsSorted() === "asc"),
      });
    },
    cell: ({ row }) => row.original.start_date_fmt,
  },
  {
    accessorKey: "end_date",
    header: ({ column }) => {
      const isSorted = column.getIsSorted();
      return h(UButton, {
        color: "neutral",
        variant: "ghost",
        label: "End Date",
        icon: isSorted
          ? isSorted === "asc"
            ? "i-lucide-arrow-up-narrow-wide"
            : "i-lucide-arrow-down-wide-narrow"
          : "i-lucide-arrow-up-down",
        class: "-mx-2.5 cursor-pointer",
        onClick: () => column.toggleSorting(column.getIsSorted() === "asc"),
      });
    },
    cell: ({ row }) => row.original.end_date_fmt,
  },
  {
    accessorKey: "evaluation_locked",
    header: "Evaluation",
    cell: ({ row }) => h(
      UBadge,
      {
        variant: "subtle",
        color: row.original.evaluation_locked ? "error" : "success",
        class: "capitalize",
      },
      () => row.original.evaluation_locked ? "Locked" : "Open"
    ),
  },
  {
    id: "actions",
    cell: ({ row }) =>
      h(
        "div",
        { class: "text-right" },
        h(
          UDropdownMenu,
          {
            content: { align: "end" },
            items: getRowItems(row),
          },
          () =>
            h(UButton, {
              icon: "i-lucide-ellipsis-vertical",
              color: "neutral",
              variant: "ghost",
              class: "cursor-pointer",
            })
        )
      ),
  },
];

// --- LOAD DATA ---
async function loadData() {
  const sort = sorting.value[0];

  console.log("Loading data with params:", {
    page: page.value,
    per_page: pageCount.value,
    sort_by: sort ? sort.id : "start_date",
    sort_direction: sort?.desc ? "desc" : "asc",
    search: search.value,
  });

  // Panggil fetchPeriods dengan parameter pagination & sorting
  // Pastikan PeriodStore support parameter ini
  await periodStore.fetchPeriods({
    page: page.value,
    per_page: pageCount.value,
    sort_by: sort ? sort.id : "start_date",
    sort_direction: sort?.desc ? "desc" : "asc",
    search: search.value,
  });

  console.log("After fetchPeriods, periodStore.periods:", periodStore.periods);
}

function onPageChange(newPage: number) {
  page.value = newPage;
  loadData();
}

// Watchers
watch(sorting, () => {
  page.value = 1;
  loadData();
});

// Initial Load
loadData();
</script>

<template>
  <UDashboardPanel id="periods">
    <template #header>
      <UDashboardNavbar title="Periods">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="['admin', 'hr', 'hr_manager'].includes(userRole)"
            label="New Period"
            icon="i-lucide-plus"
            class="cursor-pointer"
            @click="openCreateModal"
          />

          <PeriodsAddModal
            v-model="showModal"
            :periodId="selectedPeriodId"
            :initialData="selectedPeriodData"
            @saved="loadData"
          />

          <AttendanceUploadModal v-model="showUploadModal" @saved="loadData" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5 mb-4">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Search description..."
          @change="loadData"
        />

        <div class="flex flex-wrap items-center gap-1.5">
          <div v-if="['admin', 'hr'].includes(userRole)"></div>

          <UDropdownMenu
            :items="table?.tableApi?.getAllColumns()
            .filter((c: any) => c.getCanHide())
            .map((c: any) => ({
                label: titleCase(c.id),
                type: 'checkbox',
                checked: c.getIsVisible(),
                onUpdateChecked: (val: boolean) => table?.tableApi?.getColumn(c.id)?.toggleVisibility(val)
            }))
            "
            :content="{ align: 'end' }"
          >
            <UButton
              label="Display"
              color="neutral"
              variant="outline"
              trailing-icon="i-lucide-settings-2"
              class="cursor-pointer"
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
        :data="periods"
        :columns="columns"
        :loading="periodStore.loading"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
        }"
      />

      <div
        class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto"
      >
        <div class="text-sm text-muted">
          Showing {{ (page - 1) * pageCount + 1 }} to
          {{ Math.min(page * pageCount, periodStore.pagination?.total || 0) }}
          of {{ periodStore.pagination?.total || 0 }} results
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            v-model="page"
            :page-count="pageCount"
            :total="periodStore.pagination?.total || 0"
            class="[&_button]:!cursor-pointer [&_button]:!pointer-events-auto"
            @update:page="onPageChange"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>