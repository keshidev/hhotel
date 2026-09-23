import api, { fetchCsrfCookie } from './api';
import adminApi from './adminApi';
import receptionistApi from './receptionistApi';
import {
    clearRoleAuth,
    getRoleToken,
    getRoleUser,
    isStaffRole,
    resolveRole,
    setRoleAuth,
    setRoleUser,
} from './authStorage';

const getRoleApi = (role) => (role === 'admin' ? adminApi : receptionistApi);

export const clearUser = (role = null) => {
    const resolvedRole = resolveRole(role);
    if (!resolvedRole) {
        return;
    }

    clearRoleAuth(resolvedRole);
};

const authService = {
    login: async (email, password) => {
        await fetchCsrfCookie();

        const response = await api.post('/login', { email, password });
        const user = response.data?.user;
        const token = response.data?.token;

        if (user && token && isStaffRole(user.role)) {
            setRoleAuth(user.role, { user, token });
        }

        return response.data;
    },

    logout: async (role = null) => {
        const resolvedRole = resolveRole(role);
        if (!resolvedRole) {
            return;
        }

        try {
            await getRoleApi(resolvedRole).post('/logout');
        } finally {
            clearRoleAuth(resolvedRole);
        }
    },

    me: async (role = null) => {
        const resolvedRole = resolveRole(role);
        if (!resolvedRole) {
            throw new Error('No active staff role');
        }

        const response = await getRoleApi(resolvedRole).get('/me');
        if (response.data) {
            setRoleUser(resolvedRole, response.data);
        }

        return response.data;
    },

    isAuthenticated: (role = null) => {
        const resolvedRole = resolveRole(role);
        if (!resolvedRole) {
            return false;
        }

        return Boolean(getRoleToken(resolvedRole));
    },

    getUser: (role = null) => {
        const resolvedRole = resolveRole(role);
        if (!resolvedRole) {
            return null;
        }

        return getRoleUser(resolvedRole);
    },

    setUser: (userData, role = null) => {
        const resolvedRole = role || userData?.role || resolveRole();
        if (!resolvedRole || !userData) {
            return;
        }

        setRoleUser(resolvedRole, userData);
    },

    hasRole: (role) => authService.getUser(role)?.role === role,
    isAdmin: () => authService.hasRole('admin'),
    isReceptionist: () => authService.hasRole('receptionist'),

    forgotPassword: async (email) => {
        const response = await api.post('/client/forgot-password', { email });
        return response.data;
    },

    resetPassword: async (payload) => {
        const response = await api.post('/client/reset-password', payload);
        return response.data;
    },
};

export default authService;
