import axios from 'axios';
import { apiBaseUrl } from './api';
import { clearRoleAuth, getRoleToken } from './authStorage';

const adminApi = axios.create({
    baseURL: apiBaseUrl,
    withCredentials: false,
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

adminApi.interceptors.request.use((config) => {
    const token = getRoleToken('admin');

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

adminApi.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        if (status === 401) {
            clearRoleAuth('admin');

            if (window.location.pathname.startsWith('/admin')) {
                window.location.href = '/login';
            }
        }

        return Promise.reject(error);
    }
);

export default adminApi;
