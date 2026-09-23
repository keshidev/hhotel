// src/services/dashboardService.js
import api from './adminApi';

const dashboardService = {
    // Get dashboard statistics
    getStats: async (period = 'month') => {
        try {
            const response = await api.get('/admin/dashboard', {
                params: { period }
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get revenue chart data
    getRevenueChart: async (period = 'month') => {
        try {
            const response = await api.get('/admin/dashboard/revenue-chart', {
                params: { period }
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default dashboardService;
