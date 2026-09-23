// src/services/userService.js
import api from './adminApi';

const userService = {
    // Get all users with filters
    getUsers: async (params = {}) => {
        try {
            const response = await api.get('/admin/users', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single user
    getUser: async (id) => {
        try {
            const response = await api.get(`/admin/users/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Create new user
    createUser: async (userData) => {
        try {
            const response = await api.post('/admin/users', userData);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Update user
    updateUser: async (id, userData) => {
        try {
            const response = await api.put(`/admin/users/${id}`, userData);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Delete user
    deleteUser: async (id, currentPassword) => {
        try {
            const response = await api.delete(`/admin/users/${id}`, {
                data: { current_password: currentPassword },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Toggle user status (active/inactive)
    toggleStatus: async (id, currentPassword) => {
        try {
            const response = await api.patch(`/admin/users/${id}/toggle-status`, {
                current_password: currentPassword,
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Bulk delete users
    bulkDelete: async (ids, currentPassword) => {
        try {
            const response = await api.post('/admin/users/bulk-delete', {
                ids,
                current_password: currentPassword,
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default userService;
