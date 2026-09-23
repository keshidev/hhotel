// src/services/receptionist/bookingService.js
import api from '../receptionistApi';

const bookingService = {
    // Get all bookings with filters
    getBookings: async (params = {}) => {
        try {
            const response = await api.get('/receptionist/bookings', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single booking
    getBooking: async (id) => {
        try {
            const response = await api.get(`/receptionist/bookings/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get booking statistics
    getStats: async () => {
        try {
            const response = await api.get('/receptionist/bookings/stats');
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Confirm a pending booking
    confirmBooking: async (id, notes = '') => {
        try {
            const response = await api.post(`/receptionist/bookings/${id}/confirm`, { notes });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Reject a pending booking
    rejectBooking: async (id, reason) => {
        try {
            const response = await api.post(`/receptionist/bookings/${id}/reject`, { reason });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Check-in guest
    checkIn: async (id, payload = {}) => {
        try {
            const response = await api.post(`/receptionist/bookings/${id}/check-in`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    requestEarlyCheckIn: async (id, reason) => {
        const response = await api.post(`/receptionist/bookings/${id}/early-check-in-request`, { reason });
        return response.data;
    },

    // Check-out guest
    checkOut: async (id) => {
        try {
            const response = await api.post(`/receptionist/bookings/${id}/check-out`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default bookingService;
