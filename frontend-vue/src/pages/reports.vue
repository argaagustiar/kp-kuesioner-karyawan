<script setup lang="ts">
import { ref, computed } from "vue";
import { usePeriodStore } from "../stores/period";
import { useAuthStore } from "../stores/auth";
import { api } from "../services/api";

// --- STORES ---
const periodStore = usePeriodStore();
const authStore = useAuthStore();

// --- UTILS ---
const toast = useToast();

// --- STATE ---
const selectedPeriodId = ref<string | undefined>(undefined);
const loadingEvaluation = ref(false);
const loadingComments = ref(false);
const loadingEvaluationBreakdown = ref(false);
const evaluationData = ref([]);
const commentsData = ref([]);
const evaluationBreakdownData = ref([]);

// --- COMPUTED ---
const periods = computed(() => periodStore.periodOptions);

const evaluationReportReady = computed(
  () => selectedPeriodId.value && evaluationData.value.length > 0
);
const commentsReportReady = computed(
  () => selectedPeriodId.value && commentsData.value.length > 0
);
const evaluationBreakdownReady = computed(
  () => selectedPeriodId.value && evaluationBreakdownData.value.length > 0
);

// --- ACTIONS ---
async function loadReports() {
  if (!selectedPeriodId.value) {
    toast.add({
      title: "Error",
      description: "Please select a period first",
      color: "error",
    });
    return;
  }

  loadingEvaluation.value = true;
  loadingComments.value = true;
  loadingEvaluationBreakdown.value = true;

  try {
    // Load Evaluation Summary Report
    // Adjust the API endpoint as needed
    // const evalResponse = await $fetch(
    //   `/api/reports/evaluation-summary?period_id=${selectedPeriodId.value}`
    // );
    const evalResponse = await api.get("/reports/evaluation-summary", {
      params: {
        period_id: selectedPeriodId.value,
      },
    });
    console.log("Evaluation Summary Response:", evalResponse);
    evaluationData.value = evalResponse.data || [];

    // Load Comments Summary Report
    // Adjust the API endpoint as needed
    // const commentsResponse = await $fetch(
    //   `/api/reports/comments-summary?period_id=${selectedPeriodId.value}`
    // );
    const commentsResponse = await api.get("/reports/comments-summary", {
      params: {
        period_id: selectedPeriodId.value,
      },
    });
    console.log("Comments Summary Response:", commentsResponse);
    commentsData.value = commentsResponse.data || [];

    const breakdownResponse = await api.get("/reports/evaluation-breakdown", {
      params: {
        period_id: selectedPeriodId.value,
      },
    });
    console.log("Evaluation Breakdown Response:", breakdownResponse);
    evaluationBreakdownData.value = breakdownResponse.data || [];

    toast.add({
      title: "Success",
      description: "Reports loaded successfully",
      color: "success",
    });
  } catch (error) {
    console.error("Error loading reports:", error);
    toast.add({
      title: "Error",
      description: "Failed to load reports",
      color: "error",
    });
  } finally {
    loadingEvaluation.value = false;
    loadingComments.value = false;
    loadingEvaluationBreakdown.value = false;
  }
}

async function exportEvaluationReport(format: "pdf" | "xlsx") {
  if (!selectedPeriodId.value) return;

  try {
    const response = await api.get("/reports/evaluation-summary/export", {
      params: {
        period_id: selectedPeriodId.value,
        format,
      },
      responseType: "blob",
    });

    // axios puts the binary blob in `response.data`
    const blob = response.data;
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `Evaluation-Summary.${format}`;
    link.click();
    window.URL.revokeObjectURL(url);

    toast.add({
      title: "Success",
      description: `Evaluation Summary exported as ${format.toUpperCase()}`,
      color: "success",
    });
  } catch (error) {
    console.error("Error exporting evaluation report:", error);
    toast.add({
      title: "Error",
      description: "Failed to export evaluation report",
      color: "error",
    });
  }
}

async function exportCommentsReport(format: "pdf" | "xlsx") {
  if (!selectedPeriodId.value) return;

  try {
    const response = await api.get("/reports/comments-summary/export", {
      params: {
        period_id: selectedPeriodId.value,
        format,
      },
      responseType: "blob",
    });

    const blob = response.data;
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `Comments-Summary.${format}`;
    link.click();
    window.URL.revokeObjectURL(url);

    toast.add({
      title: "Success",
      description: `Comments Summary exported as ${format.toUpperCase()}`,
      color: "success",
    });
  } catch (error) {
    console.error("Error exporting comments report:", error);
    toast.add({
      title: "Error",
      description: "Failed to export comments report",
      color: "error",
    });
  }
}

async function exportEvaluationBreakdown(format: "pdf" | "xlsx") {
  if (!selectedPeriodId.value) return;

  try {
    const response = await api.get("/reports/evaluation-breakdown/export", {
      params: {
        period_id: selectedPeriodId.value,
        format,
      },
      responseType: "blob",
    });

    const blob = response.data;
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `Evaluation-Breakdown.${format}`;
    link.click();
    window.URL.revokeObjectURL(url);

    toast.add({
      title: "Success",
      description: `Evaluation Breakdown exported as ${format.toUpperCase()}`,
      color: "success",
    });
  } catch (error) {
    console.error("Error exporting evaluation breakdown:", error);
    toast.add({
      title: "Error",
      description: "Failed to export evaluation breakdown",
      color: "error",
    });
  }
}

// Initial Load - Fetch periods
async function initialLoad() {
    await periodStore.fetchPeriods({ per_page: 100 });
    selectedPeriodId.value = periodStore.periodOptions[0]?.id || undefined

    if (selectedPeriodId.value) {
      await loadReports();
    }
}

initialLoad();
</script>

<template>
  <UDashboardPanel id="reports">
    <template #header>
      <UDashboardNavbar title="Export Reports">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- Period Selection -->
      <div class="mb-6">
        <label class="block text-sm font-medium mb-2">Select Period</label>
        <!-- <USelectMenu
          v-model="selectedPeriodId"
          :options="periods"
          option-attribute="id"
          value-attribute="id"
          placeholder="Choose a period..."
          searchable
          class="w-full md:w-96"
        >
          <template #label>
            <span v-if="selectedPeriodId">
              {{
                periods.find((p) => p.id === selectedPeriodId)?.description ||
                "Select period..."
              }}
            </span>
            <span v-else class="text-gray-500">Select period...</span>
          </template>

<template #option="{ option }">
            <div class="flex items-center justify-between gap-2 w-full">
              <span>{{ option.description }}</span>
              <span class="text-xs text-gray-500">
                {{ option.start_date }} to {{ option.end_date }}
              </span>
            </div>
          </template>
</USelectMenu> -->

        <USelectMenu
          v-model="selectedPeriodId"
          class="w-full md:w-96"
          :items="periods"
          value-key="id"
          placeholder="Pilih periode"
        />
      </div>

      <!-- Reports Grid -->
      <div class="grid gap-6 md:grid-cols-2">
        <!-- Evaluation Summary Report -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-chart-bar-stacked" class="w-5 h-5" />
                <h3 class="font-semibold">Evaluation Summary</h3>
              </div>
              <UBadge
                v-if="evaluationReportReady"
                color="success"
                variant="subtle"
              >
                Ready
              </UBadge>
              <UBadge v-else color="gray" variant="subtle"> Not Ready </UBadge>
            </div>
          </template>

          <div class="space-y-4">
            <p class="text-sm text-gray-600">
              Summary of employee evaluation scores and results
            </p>

            <div
              v-if="loadingEvaluation"
              class="flex items-center justify-center py-8"
            >
              <UIcon
                name="i-lucide-loader-circle"
                class="w-5 h-5 animate-spin"
              />
              <span class="ml-2">Loading report...</span>
            </div>

            <div v-else-if="evaluationData.length > 0" class="space-y-2">
              <p class="text-xs text-gray-500">
                Total Records: {{ evaluationData.length }}
              </p>
            </div>

            <div v-else class="py-6 text-center text-gray-500">
              <p v-if="selectedPeriodId" class="text-sm">
                No data available for this period
              </p>
              <p v-else class="text-sm">
                Select a period to generate the report
              </p>
            </div>
          </div>

          <template #footer>
            <div class="flex gap-2">
              <UButton
                label="Load Report"
                icon="i-lucide-refresh-cw"
                color="primary"
                variant="soft"
                size="sm"
                :disabled="!selectedPeriodId || loadingEvaluation"
                :loading="loadingEvaluation"
                class="flex-1 cursor-pointer justify-center"
                @click="loadReports"
              />
            </div>
            <div class="flex gap-2 mt-2">
              <!-- <UButton
                label="Export PDF"
                icon="i-lucide-file-pdf"
                color="red"
                variant="outline"
                size="sm"
                :disabled="!evaluationReportReady"
                class="flex-1 cursor-pointer"
                @click="exportEvaluationReport('pdf')"
              />
              <UButton
                label="Export Excel"
                icon="i-lucide-file-spreadsheet"
                color="green"
                variant="outline"
                size="sm"
                :disabled="!evaluationReportReady"
                class="flex-1 cursor-pointer"
                @click="exportEvaluationReport('csv')"
              /> -->
              <UButton
                label="Export"
                icon="i-lucide-file-spreadsheet"
                color="success"
                variant="soft"
                size="sm"
                :disabled="!evaluationReportReady"
                :loading="loadingEvaluation"
                class="flex-1 cursor-pointer justify-center"
                @click="exportEvaluationReport('xlsx')"
              />
            </div>
          </template>
        </UCard>

        <!-- Comments Summary Report -->
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-message-square" class="w-5 h-5" />
                <h3 class="font-semibold">Comments Summary</h3>
              </div>
              <UBadge
                v-if="commentsReportReady"
                color="success"
                variant="subtle"
              >
                Ready
              </UBadge>
              <UBadge v-else color="gray" variant="subtle"> Not Ready </UBadge>
            </div>
          </template>

          <div class="space-y-4">
            <p class="text-sm text-gray-600">
              Summary of all evaluation comments from reviewers
            </p>

            <div
              v-if="loadingComments"
              class="flex items-center justify-center py-8"
            >
              <UIcon
                name="i-lucide-loader-circle"
                class="w-5 h-5 animate-spin"
              />
              <span class="ml-2">Loading report...</span>
            </div>

            <div v-else-if="commentsData.length > 0" class="space-y-2">
              <p class="text-xs text-gray-500">
                Total Comments: {{ commentsData.length }}
              </p>
            </div>

            <div v-else class="py-6 text-center text-gray-500">
              <p v-if="selectedPeriodId" class="text-sm">
                No data available for this period
              </p>
              <p v-else class="text-sm">
                Select a period to generate the report
              </p>
            </div>
          </div>

          <template #footer>
            <div class="flex gap-2">
              <UButton
                label="Load Report"
                icon="i-lucide-refresh-cw"
                color="primary"
                variant="soft"
                size="sm"
                :disabled="!selectedPeriodId || loadingComments"
                :loading="loadingComments"
                class="flex-1 cursor-pointer justify-center"
                @click="loadReports"
              />
            </div>
            <div class="flex gap-2 mt-2">
              <!-- <UButton
                label="Export PDF"
                icon="i-lucide-file-pdf"
                color="red"
                variant="outline"
                size="sm"
                :disabled="!commentsReportReady"
                class="flex-1 cursor-pointer"
                @click="exportCommentsReport('pdf')"
              />
              <UButton
                label="Export CSV"
                icon="i-lucide-file-csv"
                color="green"
                variant="outline"
                size="sm"
                :disabled="!commentsReportReady"
                class="flex-1 cursor-pointer"
                @click="exportCommentsReport('csv')"
              /> -->
              <UButton
                label="Export"
                icon="i-lucide-file-spreadsheet"
                color="success"
                variant="soft"
                size="sm"
                :disabled="!commentsReportReady"
                :loading="loadingComments"
                class="flex-1 cursor-pointer justify-center"
                @click="exportCommentsReport('xlsx')"
              />
            </div>
          </template>
        </UCard>
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <UIcon name="i-lucide-bar-chart" class="w-5 h-5" />
                <h3 class="font-semibold">Evaluation Breakdown</h3>
              </div>
              <UBadge
                v-if="evaluationBreakdownReady"
                color="success"
                variant="subtle"
              >
                Ready
              </UBadge>
              <UBadge v-else color="gray" variant="subtle"> Not Ready </UBadge>
            </div>
          </template>

          <div class="space-y-4">
            <p class="text-sm text-gray-600">
              Breakdown of all evaluation comments from reviewers
            </p>

            <div
              v-if="loadingEvaluationBreakdown"
              class="flex items-center justify-center py-8"
            >
              <UIcon
                name="i-lucide-loader-circle"
                class="w-5 h-5 animate-spin"
              />
              <span class="ml-2">Loading report...</span>
            </div>

            <div v-else-if="evaluationBreakdownData.length > 0" class="space-y-2">
              <p class="text-xs text-gray-500">
                Total Evaluation: {{ evaluationBreakdownData.length }}
              </p>
            </div>

            <div v-else class="py-6 text-center text-gray-500">
              <p v-if="selectedPeriodId" class="text-sm">
                No data available for this period
              </p>
              <p v-else class="text-sm">
                Select a period to generate the report
              </p>
            </div>
          </div>

          <template #footer>
            <div class="flex gap-2">
              <UButton
                label="Load Report"
                icon="i-lucide-refresh-cw"
                color="primary"
                variant="soft"
                size="sm"
                :disabled="!selectedPeriodId || loadingEvaluationBreakdown"
                :loading="loadingEvaluationBreakdown"
                class="flex-1 cursor-pointer justify-center"
                @click="loadReports"
              />
            </div>
            <div class="flex gap-2 mt-2">
              <UButton
                label="Export"
                icon="i-lucide-file-spreadsheet"
                color="success"
                variant="soft"
                size="sm"
                :disabled="!evaluationBreakdownReady"
                :loading="loadingEvaluationBreakdown"
                class="flex-1 cursor-pointer justify-center"
                @click="exportEvaluationBreakdown('xlsx')"
              />
            </div>
          </template>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>