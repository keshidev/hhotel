// src/services/occupancyReportService.js
import api from './adminApi';

const occupancyReportService = {
    /**
     * Fetch full occupancy report
     * GET /api/admin/reports/occupancy
     *
     * @param {string} startDate  'YYYY-MM-DD'
     * @param {string} endDate    'YYYY-MM-DD'
     * @returns { success, data: { period, stats, rooms_by_type, status_breakdown } }
     */
    getReport: async (startDate, endDate, extraParams = {}) => {
        try {
            const response = await api.get('/admin/reports/occupancy', {
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

export default occupancyReportService;
