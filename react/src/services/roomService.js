// src/services/roomService.js
import api from './adminApi';

const roomService = {
    // Get all rooms with filters
    getRooms: async (params = {}) => {
        try {
            const response = await api.get('/admin/rooms', { params });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get single room
    getRoom: async (id) => {
        try {
            const response = await api.get(`/admin/rooms/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Create room with SINGLE image upload
    createRoom: async (roomData) => {
        try {
            const formData = new FormData();
            
            // Add basic room data
            formData.append('room_number', roomData.room_number);
            formData.append('room_type', roomData.room_type);
            formData.append('capacity', roomData.capacity);
            formData.append('price_per_night', roomData.price_per_night);
            if (roomData.price_day_tour !== undefined && roomData.price_day_tour !== null && roomData.price_day_tour !== '') {
                formData.append('price_day_tour', roomData.price_day_tour);
            }
            formData.append('status', roomData.status);
            
            if (roomData.floor) {
                formData.append('floor', roomData.floor);
            }
            if (roomData.description) {
                formData.append('description', roomData.description);
            }
            
            // Add amenities
            if (roomData.amenities && Array.isArray(roomData.amenities)) {
                roomData.amenities.forEach((amenity, index) => {
                    formData.append(`amenities[${index}]`, amenity);
                });
            }
            
            //  Add single image
            if (roomData.room_image) {
                formData.append('room_image', roomData.room_image);
            }
            
            const response = await api.post('/admin/rooms', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    //  Update room with SINGLE image upload
    updateRoom: async (id, roomData) => {
        try {
            const formData = new FormData();
            formData.append('_method', 'PUT');
            
            // Add basic room data
            formData.append('room_number', roomData.room_number);
            formData.append('room_type', roomData.room_type);
            formData.append('capacity', roomData.capacity);
            formData.append('price_per_night', roomData.price_per_night);
            if (roomData.price_day_tour !== undefined && roomData.price_day_tour !== null && roomData.price_day_tour !== '') {
                formData.append('price_day_tour', roomData.price_day_tour);
            }
            formData.append('status', roomData.status);
            
            if (roomData.floor) {
                formData.append('floor', roomData.floor);
            }
            if (roomData.description) {
                formData.append('description', roomData.description);
            }
            
            // Add amenities
            if (roomData.amenities && Array.isArray(roomData.amenities)) {
                roomData.amenities.forEach((amenity, index) => {
                    formData.append(`amenities[${index}]`, amenity);
                });
            }
            
            //  Add image to keep (if exists)
            if (roomData.keep_image) {
                formData.append('keep_image', roomData.keep_image);
            }
            
            //  Add new image (if uploaded)
            if (roomData.room_image) {
                formData.append('room_image', roomData.room_image);
            }
            
            const response = await api.post(`/admin/rooms/${id}`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Delete room
    deleteRoom: async (id) => {
        try {
            const response = await api.delete(`/admin/rooms/${id}`);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Update room status
    updateRoomStatus: async (id, status) => {
        try {
            const response = await api.patch(`/admin/rooms/${id}/status`, { status });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get room types
    getRoomTypes: async () => {
        try {
            const response = await api.get('/admin/rooms/types/list');
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get floors
    getFloors: async () => {
        try {
            const response = await api.get('/admin/rooms/floors/list');
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Bulk delete rooms
    bulkDelete: async (ids) => {
        try {
            const response = await api.post('/admin/rooms/bulk-delete', { ids });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default roomService;
