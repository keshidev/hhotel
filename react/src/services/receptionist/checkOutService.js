import api from '../receptionistApi';

const checkOutService = {
  /**
   * Search guests currently checked in (eligible for check-out)
   */
  searchGuests: async (query = '') => {
    try {
      const params = { status: 'checked_in', per_page: 20 };
      if (query.trim()) params.search = query.trim();
      const response = await api.get('/receptionist/bookings', { params });
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Get a single booking by reference number or ID
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
   * Finalize check-out for a booking
   */
  finalizeCheckOut: async (id) => {
    try {
      const response = await api.post(`/receptionist/bookings/${id}/check-out`);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  checkoutRoom: async (bookingId, roomId, payload = {}) => {
    try {
      const response = await api.post(`/bookings/${bookingId}/rooms/${roomId}/checkout`, payload);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  extendRoomStay: async (bookingId, roomId, payload) => {
    try {
      const response = await api.post(`/bookings/${bookingId}/rooms/${roomId}/extend`, payload);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  settleBalance: async (id, payload) => {
    try {
      const response = await api.post(`/receptionist/bookings/${id}/settle-balance`, payload);
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Get today's departures — checked-in guests with check_out = today,
   * plus any day tour guests whose 12-hour window has expired.
   */
  getTodayDepartures: async () => {
    const today = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD local time
    const response = await api.get('/receptionist/bookings', {
      params: {
        status:     'checked_in',
        date_field: 'check_out',
        start_date: today,
        end_date:   today,
        per_page:   50,
      },
    });
    return response.data;
  },
};

export default checkOutService;
