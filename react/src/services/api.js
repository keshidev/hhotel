// src/services/api.js
import axios from 'axios';

export const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
export const backendOrigin = new URL(apiBaseUrl).origin;
let csrfCookiePromise = null;

const getCookie = (name) => {
    const escapedName = name.replace(/[-[\]/{}()*+?.\\^$|]/g, '\\$&');
    const match = document.cookie.match(new RegExp(`(?:^|; )${escapedName}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
};

const api = axios.create({
    baseURL: apiBaseUrl,
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export const fetchCsrfCookie = async (forceRefresh = false) => {
    if (csrfCookiePromise && !forceRefresh) {
        return csrfCookiePromise;
    }

    csrfCookiePromise = axios.get(`${backendOrigin}/sanctum/csrf-cookie`, {
        withCredentials: true,
        withXSRFToken: true,
        xsrfCookieName: 'XSRF-TOKEN',
        xsrfHeaderName: 'X-XSRF-TOKEN',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).catch((error) => {
        csrfCookiePromise = null;
        throw error;
    });

    return csrfCookiePromise;
};

api.interceptors.request.use((config) => {
    const xsrfToken = getCookie('XSRF-TOKEN');

    if (xsrfToken) {
        config.headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    return config;
});

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;

        if (
            error.response?.status === 419 &&
            originalRequest &&
            !originalRequest._csrfRetried
        ) {
            originalRequest._csrfRetried = true;
            await fetchCsrfCookie(true);

            return api(originalRequest);
        }

        if (error.response?.status === 422) {
            console.error('Validation error:', error.response.data.errors);
        }

        return Promise.reject(error);
    }
);

export default api;
