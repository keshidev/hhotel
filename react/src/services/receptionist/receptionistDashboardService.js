// src/services/receptionist/receptionistDashboardService.js
import api from '../receptionistApi';

const receptionistDashboardService = {
    // Get dashboard statistics
    getStats: async () => {
        try {
            const response = await api.get('/receptionist/dashboard');
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get quick stats
    getQuickStats: async () => {
        try {
            const response = await api.get('/receptionist/dashboard/quick-stats');
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default receptionistDashboardService;
