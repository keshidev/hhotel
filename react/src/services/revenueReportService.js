// src/services/revenueReportService.js
import api from './adminApi';

const revenueReportService = {
    /**
     * Fetch full revenue report
     * GET /api/admin/reports/revenue
     *
     * @param {string} startDate  'YYYY-MM-DD'
     * @param {string} endDate    'YYYY-MM-DD'
     * @returns axios response.data =
     *   { success: true, data: { period, stats, transactions, by_payment_method, daily_revenue } }
     *
     * In RevenueReport.jsx use:
     *   const res = await revenueReportService.getReport(start, end);
     *   const { stats, transactions, by_payment_method } = res.data;
     */
    getReport: async (startDate, endDate, extraParams = {}) => {
        try {
            const response = await api.get('/admin/reports/revenue', {
                params: {
                    start_date: startDate,
                    end_date:   endDate,
                    ...extraParams,
                },
            });
            return response.data; // { success, data: { period, stats, transactions, ... } }
        } catch (error) {
            throw error;
        }
    },
};

export default revenueReportService;
