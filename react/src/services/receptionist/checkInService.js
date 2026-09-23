import api from '../receptionistApi';

const checkInService = {
  /**
   * Search guests eligible for check-in.
   * Shows all confirmed bookings (walk-in and online reservations).
   */
  searchGuests: async (query = '') => {
    try {
      const params = {
        status:   'confirmed',
        per_page: 20,
      };
      if (query.trim()) params.search = query.trim();
      const response = await api.get('/receptionist/bookings', { params });
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Get a single booking by reference number or ID.
   */
  getBooking: async (id) => {
    try {
      const response = await api.get(`/receptionist/bookings/${id}`);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Complete check-in for a booking.
   */
  completeCheckIn: async (id, payload = {}) => {
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

  settleBalance: async (id, payload) => {
    const response = await api.post(`/receptionist/bookings/${id}/settle-balance`, payload);
    return response.data;
  },

  /**
   * Mark a confirmed booking as no-show.
   */
  markAsNoShow: async (id, payload = {}) => {
    try {
      const response = await api.post(`/bookings/${id}/no-show`, payload);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Get today's expected arrivals — all confirmed bookings checking in today.
   */
  getTodayArrivals: async () => {
    const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD in local time
    const response = await api.get('/receptionist/bookings', {
      params: {
        status:     'confirmed',
        date_field: 'check_in',
        start_date: today,
        end_date:   today,
        per_page:   50,
      },
    });
    return response.data;
  },
};

export default checkInService;
