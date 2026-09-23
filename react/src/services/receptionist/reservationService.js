// src/services/receptionist/reservationService.js
import api from '../receptionistApi';

const reservationService = {
    // Get all reservations with filters
    getReservations: async (params = {}) => {
        try {
            const response = await api.get('/receptionist/reservations', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single reservation
    getReservation: async (id) => {
        try {
            const response = await api.get(`/receptionist/reservations/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get available transfer rooms for a checked-in reservation
    getTransferRooms: async (id, params = {}) => {
        try {
            const response = await api.get(`/receptionist/reservations/${id}/transfer-rooms`, { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get assignable rooms for pending room assignments
    getAssignableRooms: async (bookingId, params = {}) => {
        try {
            const response = await api.get(`/bookings/${bookingId}/assignable-rooms`, { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Assign a specific room to a pending booking line
    assignRoom: async (bookingId, payload) => {
        try {
            const response = await api.post(`/bookings/${bookingId}/assign-room`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default reservationService;
