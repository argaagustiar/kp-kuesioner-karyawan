import { defineStore } from 'pinia'
import { authApi, api } from '../services/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    loading: false,
    isAuthChecked: false,
  }),

  getters: {
    // isDesign: (state) => state.user?.role === 'design',
    // isDdc: (state) => state.user?.role === 'ddc',
    // isAdmin: (state) => state.user?.role === 'admin',
    // editableFields: (state) => {
    //   const fieldPermissions = {
    //     design: ['item_key', 'customer', 'description', 'date'],
    //     ddc: ['receive', 'revision_lvl', 'page'],
    //     admin: ['item_key', 'customer', 'description', 'date', 'receive', 'revision_lvl', 'page'],
    //   };

    //   return state.user?.role ? fieldPermissions[state.user.role] || [] : [];
    // }
  },

  actions: {
    // Clear auth data
    clearAuthData() {
      this.user = null
      this.isAuthenticated = false
      localStorage.removeItem('user')
    },

    // Initialize auth state from localStorage
    async initAuth() {
      this.isAuthChecked = false
      
      try {
        await this.getMe();
        this.isAuthenticated = true;
      } catch (error) {
        console.error('Auth initialization error:', error);
        this.clearAuthData();
      } finally {
        this.isAuthChecked = true;
      }
    },

    // Login
    async login(credentials) {
      this.loading = true
      try {
        await authApi.get('sanctum/csrf-cookie');
        const response = await api.post('/login', credentials)

        await this.getMe(); 
        this.isAuthenticated = true;

        return response.data;
      } catch (error) {
        this.clearAuthData();
        console.error('Login error:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    // Logout
    async logout() {
      try {
        await api.post('/logout')
      } catch (error) {
        console.error('Logout error:', error)
      } finally {
        this.clearAuthData();      
      }
    },

    // Get current user info
    async getMe() {
      try {
        const response = await api.get('/me')
        this.user = response.data.user
        localStorage.setItem('user', JSON.stringify(this.user))
        return response.data
      } catch (error) {
        this.clearAuthData();
        console.error('GetMe error:', error)
        throw error
      }
    },
  }
})