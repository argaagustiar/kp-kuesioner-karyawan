import { defineStore } from 'pinia'
import { authApi, api } from '../services/api'
import {
  parseDate,
  DateFormatter
} from "@internationalized/date";
import { format } from 'date-fns/fp/format';

export interface Period {
    id: string;
    start_date: string;
    end_date: string;
    description?: string;
    evaluation_locked: boolean;
}

const formatDate = new DateFormatter("id-ID", { dateStyle: "medium" });

export const usePeriodStore = defineStore('period', {
    state: () => ({
        periods: [] as Period[],
        period: null as Period | null,
        loading: false,
        error: null as string | null,

        pagination: {
            page: 1,
            total: 0,
            perPage: 10
        }
    }),

    getters: {
        // Helper untuk dropdown (format { label, id })
        periodOptions: (state) => state.periods.map(p => ({
            label: `${formatDate.format(new Date(p.start_date))} - ${formatDate.format(new Date(p.end_date))}`,
            id: p.id,
            evaluation_locked: p.evaluation_locked
        }))
    },

    actions: {
        // 1. GET ALL (List Periods)
        // Menggunakan endpoint ReferenceController yang sudah dibuat
        async fetchPeriods(params = {}) {
            this.loading = true
            this.error = null
            try {
                // params bisa berisi { search: 'budi' }
                const response = await api.get('/periods', { params })
                this.periods = response.data.data
                
                // Update pagination info jika ada
                if (response.data.meta) {
                    this.pagination = {
                        page: response.data.meta.current_page || 1,
                        total: response.data.meta.total || 0,
                        perPage: response.data.meta.per_page || 10
                    }
                }
                
                console.log('Fetched Periods:', this.periods, 'Pagination:', this.pagination)
                return response.data
            } catch (error: any) {
                this.error = error.response?.data?.message || 'Gagal mengambil data periode'
                console.error('Fetch Periods error:', error)
            } finally {
                this.loading = false
            }
        },

        // 2. GET SINGLE (Detail Period)
        // Menggunakan endpoint ReferenceController
        async fetchPeriod(id: string) {
            this.loading = true
            this.error = null
            try {
                const response = await api.get(`/periods/${id}`)
                this.period = response.data.data
                return response.data.data
            } catch (error: any) {
                this.error = error.response?.data?.message || 'Gagal mengambil detail periode'
                console.error('Fetch Period Detail error:', error)
                throw error
            } finally {
                this.loading = false
            }
        },

        /**
         * NOTE: Action Create/Update/Delete di bawah ini OPTIONAL.
         * Karena strategi kita adalah "Sync dari Pihak Ketiga", biasanya 
         * kita tidak mengedit data master karyawan secara manual di aplikasi ini.
         * Tapi jika butuh edit manual, berikut implementasinya:
         */

        // 4. CREATE (Manual)
        async createPeriod(payload: any) {
            this.loading = true
            try {
                const response = await api.post('/periods', payload)
                // Tambahkan ke list state local agar tidak perlu fetch ulang
                this.periods.push(response.data)
                return response.data
            } catch (error: any) {
                console.error('Create Period error:', error)
                throw error
            } finally {
                this.loading = false
            }
        },

        // 5. UPDATE (Manual)
        async updatePeriod(id: string, payload: any) {
            this.loading = true
            try {
                const response = await api.put(`/periods/${id}`, payload)

                // Update state local
                const index = this.periods.findIndex(p => p.id === id)
                if (index !== -1) {
                    this.periods[index] = response.data
                }
                this.period = response.data // Update current view juga

                return response.data
            } catch (error: any) {
                console.error('Update Period error:', error)
                throw error
            } finally {
                this.loading = false
            }
        },

        async updateEvaluationLock(id: string, locked: boolean) {
            const response = await api.patch(`/periods/${id}/evaluation-lock`, { locked })
            const updatedPeriod = response.data.data
            const index = this.periods.findIndex(p => p.id === id)

            if (index !== -1) {
                this.periods[index] = updatedPeriod
            }
            if (this.period?.id === id) {
                this.period = updatedPeriod
            }

            return updatedPeriod
        },

        // 6. DELETE (Manual)
        async deletePeriod(id: string) {
            this.loading = true
            try {
                await api.delete(`/periods/${id}`)
                // Hapus dari state local
                this.periods = this.periods.filter(p => p.id !== id)
            } catch (error: any) {
                console.error('Delete Period error:', error)
                throw error
            } finally {
                this.loading = false
            }
        }
    }
})