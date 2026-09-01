<script setup lang="ts">
import { useTemplateRef, h, ref, watch, resolveComponent, computed } from "vue";
import { DateFormatter } from "@internationalized/date";
import { titleCase } from "scule";
import type { TableColumn } from "@nuxt/ui";
import { usePeriodStore } from "../stores/period"; // Pastikan store ini ada dan sesuai
import { useAuthStore } from "../stores/auth";
import { api } from "../services/api";
import { useRoute, useRouter } from "vue-router";
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
const formatDate = new DateFormatter("id-ID", { dateStyle: "medium" });

// --- STATE ---
const page = ref(1);
const pageCount = ref(10);
const sorting = ref([]);
const search = ref("");
const userRole = authStore.user?.role || "guest";
const periodDesc = ref("");
const showUploadModal = ref(false);
const showEditModal = ref(false);
const editingAttendanceId = ref<string | null>(null);
const editingAttendance = ref({
  employee_name: "",
  sick: 0,
  work_accident: 0,
  permit: 0,
  awol: 0,
  late_permit: 0,
  early_leave: 0,
  annual_leave: 0,
  late: 0,
  warning_letter_1: 0,
  warning_letter_2: 0,
  warning_letter_3: 0,
  subordinate_late: 0,
  subordinate_awol: 0,
});

// Attendance state
const route = useRoute();
const router = useRouter();
const periodId = ref<string | null>((route.query.period_id as string) || null);
const attendanceRecords = ref<any[]>([]);
const loading = ref(false);

// --- COMPUTED ---
// Format data attendance untuk tampilan tabel
const rows = computed(() => {
  return attendanceRecords.value.map((r) => ({
    id: r.id,
    employee_name: r.employee?.name || "-",
    sick: Number(r.sick) || 0,
    work_accident: Number(r.work_accident) || 0,
    permit: Number(r.permit) || 0,
    awol: Number(r.awol) || 0,
    late_permit: Number(r.late_permit) || 0,
    early_leave: Number(r.early_leave) || 0,
    annual_leave: Number(r.annual_leave) || 0,
    late: Number(r.late) || 0,
    warning_letter_1: Number(r.warning_letter_1) || 0,
    warning_letter_2: Number(r.warning_letter_2) || 0,
    warning_letter_3: Number(r.warning_letter_3) || 0,
    subordinate_late: Number(r.subordinate_late) || 0,
    subordinate_awol: Number(r.subordinate_awol) || 0,
  }));
});

// --- ACTIONS ---
function openCreateModal() {
  // noop for attendance page
}

function openEditModal(record: any) {
  if (!record) return;

  editingAttendanceId.value = record.id;
  editingAttendance.value = {
    employee_name: record.employee?.name || record.employee_name || "-",
    sick: Number(record.sick) || 0,
    work_accident: Number(record.work_accident) || 0,
    permit: Number(record.permit) || 0,
    awol: Number(record.awol) || 0,
    late_permit: Number(record.late_permit) || 0,
    early_leave: Number(record.early_leave) || 0,
    annual_leave: Number(record.annual_leave) || 0,
    late: Number(record.late) || 0,
    warning_letter_1: Number(record.warning_letter_1) || 0,
    warning_letter_2: Number(record.warning_letter_2) || 0,
    warning_letter_3: Number(record.warning_letter_3) || 0,
    subordinate_late: Number(record.subordinate_late) || 0,
    subordinate_awol: Number(record.subordinate_awol) || 0,
  };

  showEditModal.value = true;
}

async function handleDelete(period: any) {
  // not applicable here
}

function openUploadModal() {
  showUploadModal.value = true;
}

async function saveAttendanceEdit() {
  if (!editingAttendanceId.value || !periodId.value) {
    return;
  }

  const selectedRecord = attendanceRecords.value.find(
    (record) => record.id === editingAttendanceId.value
  );

  if (!selectedRecord) {
    return;
  }

  loading.value = true;

  try {
    await api.put(`/attendance-records/${editingAttendanceId.value}`, {
      period_id: periodId.value,
      employee_id: selectedRecord.employee_id,
      sick: Number(editingAttendance.value.sick) || 0,
      work_accident: Number(editingAttendance.value.work_accident) || 0,
      permit: Number(editingAttendance.value.permit) || 0,
      awol: Number(editingAttendance.value.awol) || 0,
      late_permit: Number(editingAttendance.value.late_permit) || 0,
      early_leave: Number(editingAttendance.value.early_leave) || 0,
      annual_leave: Number(editingAttendance.value.annual_leave) || 0,
      late: Number(editingAttendance.value.late) || 0,
      warning_letter_1: Number(editingAttendance.value.warning_letter_1) || 0,
      warning_letter_2: Number(editingAttendance.value.warning_letter_2) || 0,
      warning_letter_3: Number(editingAttendance.value.warning_letter_3) || 0,
      subordinate_late: Number(editingAttendance.value.subordinate_late) || 0,
      subordinate_awol: Number(editingAttendance.value.subordinate_awol) || 0,
    });

    toast.add({
      title: "Attendance updated",
      description: "Attendance data has been updated successfully.",
      color: "success",
    });

    showEditModal.value = false;
    await loadData();
  } catch (error: any) {
    console.error("Error updating attendance record:", error);
    toast.add({
      title: "Error",
      description:
        error.response?.data?.message ||
        "Failed to update attendance record.",
      color: "error",
    });
  } finally {
    loading.value = false;
  }
}

// --- TABLE CONFIG ---
const columnFilters = ref([]);
const columnVisibility = ref();
const rowSelection = ref({});

const attendancePagination = ref({ page: 1, total: 0, per_page: 10 });

// Menu Dropdown per Baris
function getRowItems(row: any) {
  const items: any[] = [{ type: "label", label: "Actions" }];

  // Hanya Admin/HR yang bisa edit/delete periode
  if (["admin", "hr", "hr2"].includes(userRole)) {
    items.push(
      {
        label: "Edit",
        icon: "i-lucide-edit-2",
        class: "cursor-pointer",
        onSelect: () => openEditModal(row.original),
      },
      // {
      //   label: "Delete",
      //   icon: "i-lucide-trash",
      //   class: "cursor-pointer",
      //   color: "error",
      //   onSelect: () => handleDelete(row.original),
      // }
    );
  }

  return items;
}

// Definisi Kolom Tabel (Attendance per employee)
const columns: TableColumn<any>[] = [
  {
    accessorKey: "employee_name",
    header: "Employee",
    cell: ({ row }) =>
      h("span", { class: "font-medium" }, row.original.employee_name),
  },
  { accessorKey: "sick", header: "Sick", cell: ({ row }) => row.original.sick },
  {
    accessorKey: "work_accident",
    header: "Work Accident",
    cell: ({ row }) => row.original.work_accident,
  },
  {
    accessorKey: "permit",
    header: "Permit",
    cell: ({ row }) => row.original.permit,
  },
  { accessorKey: "awol", header: "AWOL", cell: ({ row }) => row.original.awol },
  {
    accessorKey: "late_permit",
    header: "Late Permit",
    cell: ({ row }) => row.original.late_permit,
  },
  {
    accessorKey: "early_leave",
    header: "Early Leave",
    cell: ({ row }) => row.original.early_leave,
  },
  {
    accessorKey: "annual_leave",
    header: "Annual Leave",
    cell: ({ row }) => row.original.annual_leave,
  },
  { accessorKey: "late", header: "Late", cell: ({ row }) => row.original.late },
  {
    accessorKey: "warning_letter_1",
    header: "Warning Letter 1",
    cell: ({ row }) => row.original.warning_letter_1,
  },
  {
    accessorKey: "warning_letter_2",
    header: "Warning Letter 2",
    cell: ({ row }) => row.original.warning_letter_2,
  },
  {
    accessorKey: "warning_letter_3",
    header: "Warning Letter 3",
    cell: ({ row }) => row.original.warning_letter_3,
  },
  {
    accessorKey: "subordinate_late",
    header: "Subordinate Late",
    cell: ({ row }) => row.original.subordinate_late,
  },
  {
    accessorKey: "subordinate_awol",
    header: "Subordinate AWOL",
    cell: ({ row }) => row.original.subordinate_awol,
  },
  {
    id: "actions",
    header: "Actions",
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

  console.log("Loading attendance for period:", periodId.value);
  if (!periodId.value) {
    attendanceRecords.value = [];
    attendancePagination.value.total = 0;
    return;
  }

  loading.value = true;
  try {
    await periodStore.fetchPeriod(periodId.value);
    periodDesc.value = periodStore.period.description || "";
    console.log("Loaded period:", periodDesc.value);

    const response = await api.get("/attendance-records", {
      params: {
        period_id: periodId.value,
        page: page.value,
        per_page: pageCount.value,
        search: search.value,
      },
    });

    attendanceRecords.value = response.data.data;
    attendancePagination.value.page =
      response.data.meta?.current_page || page.value;
    attendancePagination.value.total = response.data.meta?.total || 0;
    attendancePagination.value.per_page =
      response.data.meta?.per_page || pageCount.value;
  } catch (error) {
    console.error("Error fetching attendance records:", error);
    toast.add({
      title: "Error",
      description: "Failed to load attendance records",
      color: "error",
    });
  } finally {
    loading.value = false;
  }
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

watch(
  () => route.query.period_id,
  (p) => {
    periodId.value = (p as string) || null;
    loadData();
  }
);

// Initial Load
loadData();
</script>

<template>
  <UDashboardPanel id="periods">
    <template #header>
      <UDashboardNavbar>
        <template #title>
          <span class="font-bold">Attendance Records {{ periodDesc }}</span>
        </template>

        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="['admin', 'hr', 'hr_manager'].includes(userRole)"
            label="Upload Attendance"
            icon="i-lucide-upload"
            class="cursor-pointer"
            @click="openUploadModal"
          />

          <UButton
            label="Back"
            icon="i-lucide-arrow-left"
            class="cursor-pointer"
            @click="router.back()"
          />

          <AttendanceUploadModal v-model="showUploadModal" :periodId="periodId" @saved="loadData" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-center justify-between gap-1.5 mb-4">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Search..."
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
        :data="rows"
        :columns="columns"
        :loading="loading"
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
          {{ Math.min(page * pageCount, attendancePagination.total || 0) }}
          of {{ attendancePagination.total || 0 }} results
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            v-model="page"
            :page-count="pageCount"
            :total="attendancePagination.total || 0"
            class="[&_button]:!cursor-pointer [&_button]:!pointer-events-auto"
            @update:page="onPageChange"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>

  <UModal
    v-model:open="showEditModal"
    title="Edit Attendance"
    :description="`Update attendance for ${editingAttendance.employee_name}`"
    :ui="{ content: 'sm:max-w-4xl' }"
  >
    <template #body>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="text-sm font-medium text-default">Employee</label>
          <div class="mt-1 rounded-md border border-default bg-muted/30 px-3 py-2 text-sm">
            {{ editingAttendance.employee_name }}
          </div>
        </div>

        <UFormField label="Sick">
          <UInput v-model.number="editingAttendance.sick" type="number" min="0" />
        </UFormField>
        <UFormField label="Work Accident">
          <UInput v-model.number="editingAttendance.work_accident" type="number" min="0" />
        </UFormField>
        <UFormField label="Permit">
          <UInput v-model.number="editingAttendance.permit" type="number" min="0" />
        </UFormField>
        <UFormField label="AWOL">
          <UInput v-model.number="editingAttendance.awol" type="number" min="0" />
        </UFormField>
        <UFormField label="Late Permit">
          <UInput v-model.number="editingAttendance.late_permit" type="number" min="0" />
        </UFormField>
        <UFormField label="Early Leave">
          <UInput v-model.number="editingAttendance.early_leave" type="number" min="0" />
        </UFormField>
        <UFormField label="Annual Leave">
          <UInput v-model.number="editingAttendance.annual_leave" type="number" min="0" />
        </UFormField>
        <UFormField label="Late">
          <UInput v-model.number="editingAttendance.late" type="number" min="0" />
        </UFormField>
        <UFormField label="Warning Letter 1">
          <UInput v-model.number="editingAttendance.warning_letter_1" type="number" min="0" />
        </UFormField>
        <UFormField label="Warning Letter 2">
          <UInput v-model.number="editingAttendance.warning_letter_2" type="number" min="0" />
        </UFormField>
        <UFormField label="Warning Letter 3">
          <UInput v-model.number="editingAttendance.warning_letter_3" type="number" min="0" />
        </UFormField>
        <UFormField label="Subordinate Late">
          <UInput v-model.number="editingAttendance.subordinate_late" type="number" min="0" />
        </UFormField>
        <UFormField label="Subordinate AWOL">
          <UInput v-model.number="editingAttendance.subordinate_awol" type="number" min="0" />
        </UFormField>
      </div>

      <div class="flex justify-end gap-2 pt-4 mt-4 border-t border-default">
        <UButton
          label="Cancel"
          color="neutral"
          variant="subtle"
          class="cursor-pointer"
          @click="showEditModal = false"
        />
        <UButton
          label="Save Changes"
          color="primary"
          variant="solid"
          :loading="loading"
          class="cursor-pointer"
          @click="saveAttendanceEdit"
        />
      </div>
    </template>
  </UModal>
</template>