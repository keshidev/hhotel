// src/services/receptionist/cancellationService.js
import api from '../receptionistApi';

const cancellationService = {
    // Get all cancellations with filters
    getCancellations: async (params = {}) => {
        try {
            const response = await api.get('/receptionist/cancellations', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single cancellation
    getCancellation: async (id) => {
        try {
            const response = await api.get(`/receptionist/cancellations/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default cancellationService;
