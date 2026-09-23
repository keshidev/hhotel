import api from './adminApi';

const auditService = {
  /**
   * GET /api/admin/audit-trail
   * Fetch paginated, filtered audit logs
   */
  getLogs: async (params = {}) => {
    try {
      const response = await api.get('/admin/audit-trail', { params });
      return response.data;
    } catch (error) {
      throw error;
    }
  },

  /**
   * GET /api/admin/audit-trail/{id}
   * Fetch full detail of a single log entry
   */
  getLog: async (id) => {
    try {
      const response = await api.get(`/admin/audit-trail/${id}`);
      return response.data;
    } catch (error) {
      throw error;
    }
  },
};

export default auditService;
