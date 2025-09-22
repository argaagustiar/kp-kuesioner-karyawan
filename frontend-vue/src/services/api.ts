import axios from 'axios'
import { useAuthStore } from '../stores/auth';

axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

const authApi = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
});

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
})

api.interceptors.response.use(
  (response) => {
    return response
  },
  (error) => {
    if (error.response?.status === 401) {
      const authStore = useAuthStore();
      authStore.clearAuthData()
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export { authApi, api }