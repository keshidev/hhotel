// src/services/amenityService.js
import api from './adminApi';

const amenityService = {
    // Fetch all amenities (default + custom)
    getAmenities: async () => {
        const response = await api.get('/admin/amenities');
        return response.data;
    },

    // Create a new custom amenity
    createAmenity: async (name) => {
        const response = await api.post('/admin/amenities', { name });
        return response.data;
    },

    // Delete a custom amenity by id
    deleteAmenity: async (id) => {
        const response = await api.delete(`/admin/amenities/${id}`);
        return response.data;
    },
};

export default amenityService;
