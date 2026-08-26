<script setup lang="ts">
import { reactive, useTemplateRef, computed, ref } from "vue";
import * as z from "zod";
import type { FormSubmitEvent } from "@nuxt/ui";
import {
  CalendarDate,
  DateFormatter,
  getLocalTimeZone,
  parseDate,
} from "@internationalized/date";
import { en } from "zod/locales";
import { useRoute, useRouter } from 'vue-router'
import { useEmployeeStore } from "../stores/employee";
import { usePeriodStore } from "../stores/period";
import { useAuthStore } from "../stores/auth";
import { useEvaluationStore } from "../stores/evaluation";
import { api } from "../services/api";

const route = useRoute();
const router = useRouter();

const employeeStore = useEmployeeStore();
const periodStore = usePeriodStore();
const authStore = useAuthStore();
const evaluationStore = useEvaluationStore();

const employeeId = route.query.employeeId as string | undefined;
const periodId = route.query.periodId as string | undefined;
const evaluatorId = route.query.evaluatorId as string ?? authStore.user?.employee_id;
const evaluationId = ref(null);
const evaluationLocked = ref(false);
const isEmployee = computed(() => authStore.user?.role === 'employee');

const isLoading = ref(false);

const schema = z.object({
  name: z.string("Required").min(2, "Too short"),
  position: z.string("Required"),
  department: z.string("Required"),
  join_date: z.custom<CalendarDate>(
    (val) => val instanceof CalendarDate,
    "Date is required"
  ),
  end_contract: z.custom<CalendarDate>(
    (val) => val instanceof CalendarDate,
    "Format date is required"
  ).optional(),
  period_cutoff: z
    .object({
      start: z.custom<CalendarDate>(
        (v) => v instanceof CalendarDate,
        "Start date is required"
      ),
      end: z.custom<CalendarDate>(
        (v) => v instanceof CalendarDate,
        "End date is required"
      ),
    })
    .refine((data) => data.start && data.end, {
      message: "Please select full range",
    }),
  evaluation_purpose: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_1: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_2: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_3: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_4: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_5: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_6: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_7: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_8: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_9: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  question_10: z
    .any()
    .refine((val) => val !== undefined && val !== null && val !== "", {
      message: "Required",
    }),
  comments: z.string().optional().nullable(),
  sick: z.number().optional(),
  work_accident: z.number().optional(),
  permit: z.number().optional(),
  awol: z.number().optional(),
  late_permit: z.number().optional(),
  early_leave: z.number().optional(),
  annual_leave: z.number().optional(),
  late: z.number().optional(),
  warning_letter_1: z.number().optional(),
  warning_letter_2: z.number().optional(),
  warning_letter_3: z.number().optional(),
  subordinate_late: z.number().optional(),
  subordinate_awol: z.number().optional(),
});

type Schema = z.output<typeof schema>;

const state = reactive<Partial<Schema>>({
  name: undefined,
  department: undefined,
  position: undefined,
  join_date: undefined,
  end_contract: undefined,
  period_cutoff: {
    start: new CalendarDate(
      new Date().getFullYear(),
      new Date().getMonth() + 1,
      new Date().getDate()
    ),
    end: new CalendarDate(
      new Date().getFullYear(),
      new Date().getMonth() + 1,
      new Date().getDate() + 7
    ),
  },
  evaluation_purpose: undefined,
  question_1: 0,
  question_2: 0,
  question_3: 0,
  question_4: 0,
  question_5: 0,
  question_6: 0,
  question_7: 0,
  question_8: 0,
  question_9: 0,
  question_10: 0,
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
  comments: "",
});

// Total A (Sum of Questions 1-10)
const totalA = computed(() => {
  return (
    (Number(state.question_1) || 0) +
    (Number(state.question_2) || 0) +
    (Number(state.question_3) || 0) +
    (Number(state.question_4) || 0) +
    (Number(state.question_5) || 0) +
    (Number(state.question_6) || 0) +
    (Number(state.question_7) || 0) +
    (Number(state.question_8) || 0) +
    (Number(state.question_9) || 0) +
    (Number(state.question_10) || 0)
  );
});

// Total B (Attendance)
const totalB = computed(() => {
  return (
    Number(state.sick) * POINTS.attendance.sick +
    Number(state.work_accident) * POINTS.attendance.work_accident +
    Number(state.permit) * POINTS.attendance.permit +
    Number(state.awol) * POINTS.attendance.awol +
    Number(state.late_permit) * POINTS.attendance.late_permit +
    Number(state.early_leave) * POINTS.attendance.early_leave +
    Number(state.annual_leave) * POINTS.attendance.annual_leave +
    Number(state.late) * POINTS.attendance.late
  );
});

// Total C (Warning Letter)
const totalC = computed(() => {
  return (
    Number(state.warning_letter_1) * POINTS.warning.first +
    Number(state.warning_letter_2) * POINTS.warning.second +
    Number(state.warning_letter_3) * POINTS.warning.third
  );
});

// Total D (Dept Head)
const totalD = computed(() => {
  return (
    Number(state.subordinate_late) * POINTS.dept_head.sub_late +
    Number(state.subordinate_awol) * POINTS.dept_head.sub_awol
  );
});

// GRAND TOTAL
const grandTotal = computed(() => {
  let grandTotal = 0;
  grandTotal = totalA.value + totalB.value + totalC.value + totalD.value;
  return grandTotal.toFixed(2);
});

const rangeValue = reactive([1, 2, 3, 4, 5]);
const POINTS = {
  attendance: {
    sick: -0.1,
    work_accident: -0.5,
    permit: -0.5,
    awol: -5.0,
    late_permit: -0.25,
    early_leave: -0.25,
    annual_leave: -0.1,
    late: -3.0,
  },
  warning: {
    first: -5,
    second: -10,
    third: -15,
  },
  dept_head: {
    sub_late: -1,
    sub_awol: -2,
  },
};

const toast = useToast();

const formatDate = new DateFormatter("id-ID", { dateStyle: "medium" });

async function onSubmit(event: FormSubmitEvent<Schema>) {
  console.log(isFormValid.value);
  if (!isFormValid.value) {
    toast.add({
      title: "Error",
      description: "Please fill all required fields correctly.",
      color: "error",
    });
    return;
  }

  if (!employeeId) {
    toast.add({
      title: "Error",
      description: "Employee ID is missing.",
      color: "error",
    });
    return;
  }

  if (!periodId) {
    toast.add({
      title: "Error",
      description: "Period ID is missing.",
      color: "error",
    });
    return;
  }

  if (isEmployee.value && evaluationLocked.value) {
    toast.add({
      title: "Evaluation locked",
      description: "This evaluation period is locked by an administrator.",
      color: "warning",
    });
    return;
  }

  isLoading.value = true;
  const payload = {
    id: evaluationId.value,
    employee_id: employeeId,
    period_id: periodId,
    evaluator_id: evaluatorId,
    period_start: state.period_cutoff?.start
      ? state.period_cutoff.start.toString()
      : null,
    period_end: state.period_cutoff?.end
      ? state.period_cutoff.end.toString()
      : null,
    evaluation_purpose: event.data.evaluation_purpose,
    question_1: event.data.question_1,
    question_2: event.data.question_2,
    question_3: event.data.question_3,
    question_4: event.data.question_4,
    question_5: event.data.question_5,
    question_6: event.data.question_6,
    question_7: event.data.question_7,
    question_8: event.data.question_8,
    question_9: event.data.question_9,
    question_10: event.data.question_10,
    comments: event.data.comments || "",
  }
  console.log("Payload: ", payload)

  try {
    if (evaluationId.value) {
      await evaluationStore.updateEvaluation(evaluationId.value, payload);
      toast.add({ title: 'Success', description: 'Evaluation updated successfully', color: 'success', position: 'center' });
    } else {
      const evaluation = await evaluationStore.createEvaluation(payload);
      console.log("Created Evaluation: ", evaluation)
      evaluationId.value = evaluation.data.id;
      toast.add({ title: 'Success', description: 'Evaluation created successfully', color: 'success', position: 'center' });
    }

    router.push(`/employees`);
  } catch (error) {
    console.error('Error saving evaluation:', error)
    toast.add({ 
      title: `Failed to ${evaluationId.value ? 'update' : 'create'}`, 
      description: `${error.response?.data?.message}`, 
      color: 'error' 
    })
  } finally {
    isLoading.value = false;
  }
}

async function loadEmployeeData() {
  const employee = await employeeStore.fetchEmployee(employeeId);
  if (employee) {
    console.log("Employee: ", employee)
    state.name = employee.name;
    state.position = employee.position?.title;
    state.department = employee.department?.name;
    state.join_date = parseDate(employee.join_date);
    state.end_contract = employee.end_contract_date ? parseDate(employee.end_contract_date) : undefined;
  }

  const period = await periodStore.fetchPeriod(periodId);
  if (period) {
    evaluationLocked.value = period.evaluation_locked;
    state.period_cutoff = {
      start: parseDate(period.start_date),
      end: parseDate(period.end_date),
    };
  }

  const existingEvaluation = await evaluationStore.fetchEvaluations({employee_id: employeeId, period_id: periodId, evaluator_id: evaluatorId});
  if (existingEvaluation.data && existingEvaluation.data.length > 0) {
    const evalData = existingEvaluation.data[0];
    console.log("Existing Evaluation: ", evalData)
    evaluationId.value = evalData.id;
    state.evaluation_purpose = evalData.evaluation_purpose;
    state.question_1 = String(evalData.question_1);
    state.question_2 = String(evalData.question_2);
    state.question_3 = String(evalData.question_3);
    state.question_4 = String(evalData.question_4);
    state.question_5 = String(evalData.question_5);
    state.question_6 = String(evalData.question_6);
    state.question_7 = String(evalData.question_7);
    state.question_8 = String(evalData.question_8);
    state.question_9 = String(evalData.question_9);
    state.question_10 = String(evalData.question_10);
    state.comments = evalData.comments || "";
  }

  const attendanceRecord = await api.get(`/attendance-records/period/${periodId}/employee/${employeeId}`);
  if (attendanceRecord && attendanceRecord.data && attendanceRecord.data.data) {
    console.log("Attendance Record: ", attendanceRecord.data.data)
    state.sick = Number(attendanceRecord.data.data.sick) || 0;
    state.work_accident = Number(attendanceRecord.data.data.work_accident) || 0;
    state.permit = Number(attendanceRecord.data.data.permit) || 0;
    state.awol = Number(attendanceRecord.data.data.awol) || 0;
    state.late_permit = Number(attendanceRecord.data.data.late_permit) || 0;
    state.early_leave = Number(attendanceRecord.data.data.early_leave) || 0;
    state.annual_leave = Number(attendanceRecord.data.data.annual_leave) || 0;
    state.late = Number(attendanceRecord.data.data.late) || 0;
  }
}

const rangeLabel = computed(() => {
  if (!state.period_cutoff.start) return "Pilih Rentang Tanggal";

  const start = formatDate.format(
    state.period_cutoff.start.toDate(getLocalTimeZone())
  );

  if (!state.period_cutoff.end) return `${start} - ...`;

  const end = formatDate.format(
    state.period_cutoff.end.toDate(getLocalTimeZone())
  );
  return `${start} - ${end}`;
});

const isFormValid = computed(() => {
  // Cek data validation with zod
  const result = schema.safeParse(state);
  return result.success;
});

console.log("Employee ID: ", employeeId);
if (employeeId) {
  loadEmployeeData();
}
</script>
<template>
  <UDashboardPanel id="form">
    <template #header>
      <UDashboardNavbar
        title="Penilaian Kinerja (Performance Evaluation)"
        :ui="{ right: 'gap-3' }"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="isEmployee && evaluationLocked"
        color="warning"
        icon="i-lucide-lock"
        title="Evaluation locked"
        description="This period is locked by an administrator. You can view the form but cannot save changes."
        class="mb-4"
      />
      <h1 class="text-center text-4xl font-bold">Penilaian Kinerja Karyawan (Performance Evaluation)</h1>
      <h1 class="text-center text-4xl font-bold">PT. Kanepackage Indonesia</h1>

      <USeparator
        size="sm"
        type="solid"
        color="info"
      />

      <UForm
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField
            name="name"
            label="Nama (Name)"
            required
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UInput
              v-model="state.name"
              autocomplete="off"
              class="w-64 min-w-[120px]"
              disabled
            />
          </UFormField>
          <UFormField
            name="position"
            label="Posisi (Position) & Status"
            required
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UInput
              v-model="state.position"
              autocomplete="off"
              class="w-64 min-w-[120px]"
              disabled
            />
          </UFormField>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField
            name="department"
            label="Bagian atau divisi (Department or Division)"
            required
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UInput
              v-model="state.department"
              autocomplete="off"
              class="w-64 min-w-[120px]"
              disabled
            />
          </UFormField>
          <UFormField
            name="join_date"
            label="Tanggal Masuk"
            required
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UPopover :ui="{ content: 'p-0' }">
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-calendar"
                class="w-64 min-w-[120px] justify-start text-left font-normal cursor-pointer"
                :class="!state.join_date && 'text-gray-500'"
                disabled
              >
                {{
                  state.join_date
                    ? formatDate.format(state.join_date.toDate(getLocalTimeZone()))
                    : "Pick a date"
                }}
              </UButton>

              <template #content>
                <UCalendar v-model="state.join_date" class="p-2" />
              </template>
            </UPopover>
          </UFormField>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <UFormField
            name="period_cutoff"
            label="Cut off Data Absen"
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UPopover :ui="{ content: 'p-0 w-auto' }">
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-calendar-days"
                class="w-64 min-w-[120px] justify-start text-left font-normal"
                disabled
              >
                {{ rangeLabel }}
              </UButton>

              <template #content>
                <UCalendar
                  v-model="state.period_cutoff"
                  range
                  :number-of-months="2"
                  class="p-2"
                />
              </template>
            </UPopover>
          </UFormField>
          <UFormField
            name="end_contract"
            label="Tanggal Habis Kontrak (End Contract)"
            class="flex max-sm:flex-col items-center gap-4"
            :ui="{ label: 'w-64 min-w-[120px]' }"
          >
            <UPopover :ui="{ content: 'p-0' }">
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-calendar"
                class="w-64 min-w-[120px] justify-start text-left font-normal cursor-pointer"
                :class="!state.end_contract && 'text-gray-500'"
                disabled
              >
                {{
                  state.end_contract
                    ? formatDate.format(
                        state.end_contract.toDate(getLocalTimeZone())
                      )
                    : "Pick a date"
                }}
              </UButton>

              <template #content>
                <UCalendar v-model="state.end_contract" class="p-2" />
              </template>
            </UPopover>
          </UFormField>
        </div>
        <UFormField
          name="evaluation_purpose"
          label="Jenis Evaluasi (Evaluation Type)"
          required
          class="flex max-sm:flex-col items-center gap-4"
          :ui="{ label: 'w-64 min-w-[120px]' }"
        >
          <div class="grid grid-cols-1 sm:grid-cols-5 gap-4">
            <UCheckbox
              label="Perpanjangan Kontrak (Renewal Contract)"
              :model-value="state.evaluation_purpose === 'renewal'"
              @update:model-value="
                (checked) => (state.evaluation_purpose = checked ? 'renewal' : undefined)
              "
            />
            <UCheckbox
              label="Konfirmasi Staff Baru / Karyawan Tetap"
              :model-value="state.evaluation_purpose === 'staff_regular'"
              @update:model-value="
                (checked) =>
                  (state.evaluation_purpose = checked ? 'staff_regular' : undefined)
              "
            />
            <UCheckbox
              label="Promosi (Promotion)"
              :model-value="state.evaluation_purpose === 'promotion'"
              @update:model-value="
                (checked) => (state.evaluation_purpose = checked ? 'promotion' : undefined)
              "
            />
            <UCheckbox
              label="Penilaian Pertengahan Tahun (Mid Year Appraisal)"
              :model-value="state.evaluation_purpose === 'mid_year'"
              @update:model-value="
                (checked) => (state.evaluation_purpose = checked ? 'mid_year' : undefined)
              "
            />
            <UCheckbox
              label="Penilaian Akhir Tahun (Year End Appraisal)"
              :model-value="state.evaluation_purpose === 'year_end'"
              @update:model-value="
                (checked) => (state.evaluation_purpose = checked ? 'year_end' : undefined)
              "
            />
          </div>
        </UFormField>

        <USeparator
          size="sm"
          label="A. ATTITUDE, ABILITY AND PERFORMANCE"
          type="solid"
          color="info"
        />

        <!-- Questions -->
        <UFormField
          name="question_1"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                1. Komitmen kepada pelanggan, masyarakat dan Peraturan perusahaan. Moralitas, etika dan martabat sebagai anggota pasar/rantai Pemasok di masyarakat.
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Commitment to customers, the society and the company policy.
                Morality, ethics and dignity as a member of the market/Supply-chain
                and the society.
                <br class="my-1">
                顧客、社会、会社のポリシーへのコミットメント。市場、サプライチェーン、社会のメンバーとしての道徳、倫理、品位
              </span>
            </div>
          </template>

          <URadioGroup
            v-model="state.question_1"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
            class="w-full"
          />
        </UFormField>

        <UFormField
          name="question_2"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                2. Sikap kerja yang serius, sungguh-sungguh dan tulus. Konsentrasi/fokus bekerja. Rasa tanggung jawab. Kepatuhan dengan aturan & instruksi
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Serious, earnest and sincere work attitude. Concentration/focus to
                work. Sense of responsibility. Compliance with rules & instructions
                <br class="my-1">
                真摯で誠実な勤務態度、集中力、責任感、ルールや指示の遵守
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_2"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_3"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                3. Harmoni. Mempertimbangkan/Perhatian terhadap orang lain. Adab/Halus budi/sopan
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Harmony. Consideration/Care for others.
                Etiquette/courtesy/manners/politeness.
                <br class="my-1">
                和、配慮、礼節
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_3"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_4"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                4. Fleksibilitas, koperatif, kerja sama
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Flexibility, Cooperation, Teamwork
                <br class="my-1">
                柔軟さ、協力する姿勢、チームワーク
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_4"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_5"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                5. Pengetahuan dan Kemampuan
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Knowledge and Skils
                <br class="my-1">
                知識と技術
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_5"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_6"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                6. Kemampuan Menyusun/Mengatur/Merencanakan/Mengkoordinasikan. Kemampuan berkomunikasi. Ho-Ren-so. Berbagi informasi. Melaporkan
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Ability of Arrangement/Setup/Plan/Coordinate. Communication skill.
                Ho-Ren-So. Information sharing. Reporting
                <br class="my-1">
                コーディネートや段取りや根回しの能力、コミュニケーション能力、報連相、情報共有
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_6"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_7"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                7. Tindakan/Kemampuan untuk menyelesaikan sesuatu. Aktif / Antusias. Tindakan/respon cepat
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Action/The ability to get things done. Active/Enthusiastic. Quick
                action/respose
                <br class="my-1">
                実行力、行動の迅速さ、完遂する力, 積極性／能動性
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_7"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_8"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                8. Berpikir logis. Analisis. Kreativitas/Inovatif. Penyelesaian masalah. Kaizen/Peningkatan
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Logical thinking. Analysis. Creativity/Innovativeness. Problem
                solving. Kaizen/Improvement
                <br class="my-1">
                論理的思考、分析能力、創造性、課題解決、カイゼン
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_8"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_9"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                9. Kemampuan dan Akurasi untuk membuat Dokumen/Laporan/Data/Email
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Ability & Accuracy to create Documents/Reports/Data/Emails
                <br class="my-1">
                書類、メール、データ、報告書、資料等の作成能力や正確さ
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_9"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <UFormField
          name="question_10"
          required
          class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4 w-full"
          :ui="{
            label: 'w-245 min-w-[120px]',
            description: 'mt-2 w-245 min-w-[120px]',
          }"
        >
          <template #label>
            <div class="space-y-1">
              <span class="block text-gray-900 font-medium">
                10. Kontribusi untuk mencapai target departemen
              </span>
              
              <span class="block text-sm text-gray-500 font-normal leading-snug">
                Contribution to achieving department's goals
                <br class="my-1">
                部門の目標達成への貢献
              </span>
            </div>
          </template>
          <URadioGroup
            v-model="state.question_10"
            :items="rangeValue"
            variant="table"
            orientation="horizontal"
          />
        </UFormField>

        <div class="flex justify-end font-bold text-primary mt-2">
          Total A Score: {{ totalA }}
        </div>

        <USeparator
          size="sm"
          type="solid"
          color="info"
        />

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
          <div
            class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden"
          >
            <div
              class="bg-gray-100 dark:bg-gray-800 p-2 font-bold border-b border-gray-200 dark:border-gray-700"
            >
              B. KEHADIRAN / ATTENDANCE
            </div>
            <table class="w-full text-sm text-left">
              <thead
                class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700"
              >
                <tr>
                  <th class="p-2 w-1/2">Item</th>
                  <th class="p-2 text-center">Point</th>
                  <th class="p-2 text-center w-16">Qty</th>
                  <th class="p-2 text-right">Score</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr
                  v-for="(item, idx) in [
                    {
                      label: 'Sakit (Sick)',
                      key: 'sick',
                      point: POINTS.attendance.sick,
                    },
                    {
                      label: 'Kecelakaan Kerja',
                      key: 'work_accident',
                      point: POINTS.attendance.work_accident,
                    },
                    {
                      label: 'Izin (Permit)',
                      key: 'permit',
                      point: POINTS.attendance.permit,
                    },
                    {
                      label: 'Mangkir (AWOL)',
                      key: 'awol',
                      point: POINTS.attendance.awol,
                    },
                    {
                      label: 'Izin Terlambat',
                      key: 'late_permit',
                      point: POINTS.attendance.late_permit,
                    },
                    {
                      label: 'Izin Pulang Cepat',
                      key: 'early_leave',
                      point: POINTS.attendance.early_leave,
                    },
                    {
                      label: 'Cuti Tahunan',
                      key: 'annual_leave',
                      point: POINTS.attendance.annual_leave,
                    },
                    {
                      label: 'Terlambat (Late)',
                      key: 'late',
                      point: POINTS.attendance.late,
                    },
                  ]"
                  :key="idx"
                >
                  <td class="p-2">{{ idx + 1 }}. {{ item.label }}</td>
                  <td class="p-2 text-center text-gray-500">
                    {{ item.point.toFixed(2) }}
                  </td>
                  <td class="p-2">
                    <UInputNumber
                      v-model="state[item.key as keyof typeof state]"
                      size="s"
                      :min="0"
                      class="w-16 text-center"
                      :ui="{ 
                        increment: 'hidden', 
                        decrement: 'hidden',
                      }"
                      disabled
                    />
                  </td>
                  <td class="p-2 text-right font-medium">
                    {{ (state[item.key as keyof typeof state] * item.point).toFixed(2) }}
                  </td>
                </tr>
                <tr class="bg-gray-50 dark:bg-gray-800 font-bold">
                  <td colspan="3" class="p-2 text-right">TOTAL B*</td>
                  <td class="p-2 text-right">{{ totalB.toFixed(2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex flex-col gap-6">
            <div
              class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden"
            >
              <div
                class="bg-gray-100 dark:bg-gray-800 p-2 font-bold border-b border-gray-200 dark:border-gray-700"
              >
                C. PERINGATAN / WARNING LETTER
              </div>
              <table class="w-full text-sm text-left">
                <thead
                  class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700"
                >
                  <tr>
                    <th class="p-2 w-1/2">Item</th>
                    <th class="p-2 text-center">Point</th>
                    <th class="p-2 text-center w-16">Qty</th>
                    <th class="p-2 text-right">Score</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                  <tr
                    v-for="(item, idx) in [
                      {
                        label: 'Peringatan Pertama',
                        key: 'warning_letter_1',
                        point: POINTS.warning.first,
                      },
                      {
                        label: 'Peringatan Kedua',
                        key: 'warning_letter_2',
                        point: POINTS.warning.second,
                      },
                      {
                        label: 'Peringatan Ketiga',
                        key: 'warning_letter_3',
                        point: POINTS.warning.third,
                      },
                    ]"
                    :key="idx"
                  >
                    <td class="p-2">{{ idx + 1 }}. {{ item.label }}</td>
                    <td class="p-2 text-center text-gray-500">
                      {{ item.point }}
                    </td>
                    <td class="p-2">
                      <UInputNumber
                        v-model="state[item.key as keyof typeof state]"
                        size="s"
                        :min="0"
                        class="w-16 text-center"
                        :ui="{ 
                          increment: 'hidden', 
                          decrement: 'hidden',
                        }"
                        disabled
                      />
                    </td>
                    <td class="p-2 text-right font-medium">
                      {{ (state[item.key as keyof typeof state] * item.point).toFixed(2) }}
                    </td>
                  </tr>
                  <tr class="bg-gray-50 dark:bg-gray-800 font-bold">
                    <td colspan="3" class="p-2 text-right">TOTAL C*</td>
                    <td class="p-2 text-right">{{ totalC.toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden"
            >
              <div
                class="bg-gray-100 dark:bg-gray-800 p-2 font-bold border-b border-gray-200 dark:border-gray-700"
              >
                D. DEPT HEAD EVALUATION
              </div>
              <table class="w-full text-sm text-left">
                <thead
                  class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700"
                >
                  <tr>
                    <th class="p-2 w-1/2">Item</th>
                    <th class="p-2 text-center">Point</th>
                    <th class="p-2 text-center w-16">Qty</th>
                    <th class="p-2 text-right">Score</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                  <tr
                    v-for="(item, idx) in [
                      {
                        label: 'Bawahan Terlambat',
                        key: 'subordinate_late',
                        point: POINTS.dept_head.sub_late,
                      },
                      {
                        label: 'Bawahan Mangkir',
                        key: 'subordinate_awol',
                        point: POINTS.dept_head.sub_awol,
                      },
                    ]"
                    :key="idx"
                  >
                    <td class="p-2">{{ idx + 1 }}. {{ item.label }}</td>
                    <td class="p-2 text-center text-gray-500">
                      {{ item.point }}
                    </td>
                    <td class="p-2">
                      <UInputNumber
                        v-model="state[item.key as keyof typeof state]"
                        size="s"
                        :min="0"
                        class="w-16 text-center"
                        :ui="{ 
                          increment: 'hidden', 
                          decrement: 'hidden',
                        }"
                        disabled
                      />
                    </td>
                    <td class="p-2 text-right font-medium">
                      {{ (state[item.key as keyof typeof state] * item.point).toFixed(2) }}
                    </td>
                  </tr>
                  <tr class="bg-gray-50 dark:bg-gray-800 font-bold">
                    <td colspan="3" class="p-2 text-right">TOTAL D*</td>
                    <td class="p-2 text-right">{{ totalD.toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div
              class="bg-orange-100 dark:bg-orange-900/30 border border-orange-200 dark:border-orange-800 p-4 rounded-md flex justify-between items-center"
            >
              <span
                class="text-lg font-bold text-orange-900 dark:text-orange-100"
                >TOTAL (A + B + C + D)
              </span>
              <span
                class="text-2xl font-black text-orange-600 dark:text-orange-400"
                >{{ grandTotal }}
              </span>
            </div>
          </div>
        </div>
        <div>
          <div
            class="bg-gray-100 dark:bg-gray-800 p-2 font-bold border-b border-gray-200 dark:border-gray-700"
          >
            COMMENT / NOTE / REMARKS
          </div>
          <UTextarea
            v-model="state.comments"
            name="comments"
            label="Comments"
            rows="4"
            class="w-full"
          />
        </div>

        <UButton type="submit" class="cursor-pointer" :loading="isLoading"> 
          Submit
        </UButton>
      </UForm>
    </template>
  </UDashboardPanel>
</template>
