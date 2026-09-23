import api from '../adminApi';

const approvalService = {
  // Cancellation requests
  getCancellationRequest: async (id) => {
    const response = await api.get(`/admin/cancellation-requests/${id}`);
    return response.data;
  },
  getCancellationRequests: async (params = {}) => {
    const response = await api.get('/admin/cancellation-requests', { params });
    return response.data;
  },

  approveCancellationRequest: async (id, payload = {}) => {
    const response = await api.post(`/admin/cancellation-requests/${id}/approve`, payload);
    return response.data;
  },

  rejectCancellationRequest: async (id, payload) => {
    const response = await api.post(`/admin/cancellation-requests/${id}/reject`, payload);
    return response.data;
  },

  processRefundForCancellationRequest: async (id, payload = {}) => {
    const config = payload instanceof FormData
      ? { headers: { 'Content-Type': 'multipart/form-data' } }
      : undefined;
    const response = await api.post(`/admin/cancellation-requests/${id}/refund`, payload, config);
    return response.data;
  },

  getRefundProofForCancellationRequest: async (id) => {
    const response = await api.get(`/admin/cancellation-requests/${id}/refund-proof`, {
      responseType: 'blob',
    });
    return response.data;
  },

  finalizeCancellationRequest: async (id, payload = {}) => {
    const response = await api.post(`/admin/cancellation-requests/${id}/finalize`, payload);
    return response.data;
  },

  // Transfer requests
  getTransferRequests: async (params = {}) => {
    const response = await api.get('/admin/transfer-requests', { params });
    return response.data;
  },

  approveTransferRequest: async (id, payload = {}) => {
    const response = await api.post(`/admin/transfer-requests/${id}/approve`, payload);
    return response.data;
  },

  rejectTransferRequest: async (id, payload) => {
    const response = await api.post(`/admin/transfer-requests/${id}/reject`, payload);
    return response.data;
  },

  completeTransferRequest: async (id, payload = {}) => {
    const response = await api.post(`/admin/transfer-requests/${id}/complete`, payload);
    return response.data;
  },

  // Early check-in requests
  getEarlyCheckInRequests: async (params = {}) => {
    const response = await api.get('/admin/early-check-in-requests', { params });
    return response.data;
  },

  approveEarlyCheckInRequest: async (id, payload = {}) => {
    const response = await api.post(`/admin/early-check-in-requests/${id}/approve`, payload);
    return response.data;
  },

  rejectEarlyCheckInRequest: async (id, payload) => {
    const response = await api.post(`/admin/early-check-in-requests/${id}/reject`, payload);
    return response.data;
  },
};

export default approvalService;
