import axios from 'axios';
import { apiBaseUrl } from './api';
import { clearRoleAuth, getRoleToken } from './authStorage';

const receptionistApi = axios.create({
    baseURL: apiBaseUrl,
    withCredentials: false,
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

receptionistApi.interceptors.request.use((config) => {
    const token = getRoleToken('receptionist');

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

receptionistApi.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        if (status === 401) {
            clearRoleAuth('receptionist');

            if (window.location.pathname.startsWith('/receptionist')) {
                window.location.href = '/login';
            }
        }

        return Promise.reject(error);
    }
);

export default receptionistApi;
