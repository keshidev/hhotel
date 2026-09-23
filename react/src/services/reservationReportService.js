// src/services/reservationReportService.js
import api from './adminApi';

const reservationReportService = {
    /**
     * Fetch full reservation report
     * GET /api/admin/reports/reservations
     *
     * @param {string} startDate  'YYYY-MM-DD'
     * @param {string} endDate    'YYYY-MM-DD'
     * @returns { success, data: { period, stats, reservations, status_breakdown } }
     */
    getReport: async (startDate, endDate, extraParams = {}) => {
        try {
            const response = await api.get('/admin/reports/reservations', {
                params: {
                    start_date: startDate,
                    end_date:   endDate,
                    ...extraParams,
                },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    /**
     * Fetch modified reservations report
     * GET /api/admin/reports/reservations/modified
     */
    getModifiedReport: async (startDate, endDate, extraParams = {}) => {
        try {
            const response = await api.get('/admin/reports/reservations/modified', {
                params: {
                    start_date: startDate,
                    end_date:   endDate,
                    ...extraParams,
                },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default reservationReportService;
