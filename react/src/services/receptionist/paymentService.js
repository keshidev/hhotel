// src/services/receptionist/paymentService.js
import api from '../receptionistApi';

const paymentService = {
    // Get all payments with filters
    getPayments: async (params = {}) => {
        try {
            const response = await api.get('/receptionist/payments', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single payment
    getPayment: async (id) => {
        try {
            const response = await api.get(`/receptionist/payments/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Accept payment
    acceptPayment: async (id) => {
        try {
            const response = await api.post(`/receptionist/payments/${id}/accept`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Reject payment
    rejectPayment: async (id, reason = '') => {
        try {
            const response = await api.post(`/receptionist/payments/${id}/reject`, { reason });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default paymentService;
