import api from '../receptionistApi';

const transferRequestService = {
  getRequests: async (params = {}) => {
    const response = await api.get('/receptionist/transfer-requests', { params });
    return response.data;
  },

  getRequest: async (id) => {
    const response = await api.get(`/receptionist/transfer-requests/${id}`);
    return response.data;
  },

  createRequest: async (payload) => {
    const response = await api.post('/receptionist/transfer-requests', payload);
    return response.data;
  },
};

export default transferRequestService;

