// src/services/receptionist/rebookingService.js
import adminApi from '../adminApi';
import receptionistApi from '../receptionistApi';

const getRoleApi = (role) => (role === 'admin' ? adminApi : receptionistApi);

const rebookingService = {
    // Get all rebookings with filters
    getRebookings: async (params = {}, role = 'receptionist') => {
        try {
            const response = await getRoleApi(role).get(`/${role}/rebookings`, { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single rebooking
    getRebooking: async (id, role = 'receptionist') => {
        try {
            const response = await getRoleApi(role).get(`/${role}/rebookings/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Approve pending rebooking request
    approveRebooking: async (id, payload = {}, role = 'receptionist') => {
        try {
            const response = await getRoleApi(role).post(`/${role}/rebookings/${id}/approve`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Reject pending rebooking request
    rejectRebooking: async (id, payload = {}, role = 'receptionist') => {
        try {
            const response = await getRoleApi(role).post(`/${role}/rebookings/${id}/reject`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    requestAdditionalPayment: async (id, role = 'receptionist') => {
        const response = await getRoleApi(role).post(`/${role}/rebookings/${id}/request-payment`);
        return response.data;
    },

    sendForRefundReview: async (id, role = 'receptionist') => {
        const response = await getRoleApi(role).post(`/${role}/rebookings/${id}/refund-review`);
        return response.data;
    },

    processRefund: async (id, payload) => {
        const response = await adminApi.post(`/admin/rebookings/${id}/refund`, payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data;
    },

    getRefundProof: async (id) => {
        const response = await adminApi.get(`/admin/rebookings/${id}/refund-proof`, { responseType: 'blob' });
        return response.data;
    },
};

export default rebookingService;
