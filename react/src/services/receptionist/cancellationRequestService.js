import api from '../receptionistApi';

const cancellationRequestService = {
  getRequests: async (params = {}) => {
    const response = await api.get('/receptionist/cancellation-requests', { params });
    return response.data;
  },

  getRequest: async (id) => {
    const response = await api.get(`/receptionist/cancellation-requests/${id}`);
    return response.data;
  },
};

export default cancellationRequestService;
