// src/services/client/clientBookingService.js
import axios from 'axios';

const clientApi = axios.create({
    baseURL: (import.meta.env.VITE_API_URL || 'http://localhost:8000/api') + '/client',
    withCredentials: true,
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    },
});

const clientBookingService = {

    prepareManualGcashPayment: async (bookingId) => {
        const response = await clientApi.post(`/manual-gcash/${bookingId}/prepare`);
        return response.data;
    },

    getManualGcashStatus: async (bookingId) => {
        const response = await clientApi.get(`/manual-gcash/${bookingId}/status`);
        return response.data;
    },

    submitManualGcashProof: async (bookingId, formData) => {
        const response = await clientApi.post(`/manual-gcash/${bookingId}/proof`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data;
    },

    resumeManualGcashPayment: async (bookingId) => {
        const response = await clientApi.post(`/manual-gcash/${bookingId}/resume`);
        return response.data;
    },

    // Create a new booking
    createBooking: async (bookingData, { idempotencyKey } = {}) => {
        try {
            const response = await clientApi.post('/bookings', {
                guest_name:       bookingData.guest_name,
                guest_email:      bookingData.guest_email,
                guest_phone:      bookingData.guest_phone,
                guest_country:    bookingData.guest_country,
                guest_address_line_1: bookingData.guest_address_line_1,
                guest_address_line_2: bookingData.guest_address_line_2 || null,
                guest_city:       bookingData.guest_city,
                guest_postal_code: bookingData.guest_postal_code,
                check_in:         bookingData.check_in,
                check_out:        bookingData.check_out,
                number_of_guests: bookingData.number_of_guests,
                adults_count:     bookingData.adults_count,
                children_count:   bookingData.children_count,
                children_ages:    bookingData.children_ages || [],
                payment_method:   'gcash',
                room_ids:         bookingData.room_ids,
                room_types:       bookingData.room_types || [],
                room_addons:      bookingData.room_addons || {},
                special_requests: bookingData.special_requests || null,
                captcha_token:    bookingData.captcha_token    || null,
                promo_code:       bookingData.promo_code       || null,
            }, {
                headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Get available rooms
    getAvailableRooms: async (checkIn, checkOut, numberOfGuests) => {
        try {
            const response = await clientApi.get('/rooms/available', {
                params: {
                    check_in:         checkIn,
                    check_out:        checkOut,
                    number_of_guests: numberOfGuests || 2,
                }
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Check booking status by email + reference
    checkBookingStatus: async (email, referenceNumber) => {
        try {
            const response = await clientApi.post('/bookings/check-status', {
                email,
                reference_number: referenceNumber,
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Cancel booking (idempotent)
    cancelBooking: async (bookingId, { reason, refundRecipient } = {}) => {
        try {
            const payload = {};

            if (reason) payload.reason = reason;
            if (refundRecipient) {
                payload.refund_recipient_name = refundRecipient.name;
                payload.refund_recipient_account = refundRecipient.account;
                payload.refund_recipient_confirmed = refundRecipient.confirmed;
            }

            const response = await clientApi.post(`/bookings/${bookingId}/cancel`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    // Submit rebooking request (line-item aware; supports legacy single-room payloads)
    requestRebooking: async (bookingId, {
        roomChanges,
        newRoomId,
        bookingRoomId,
        originalBookingRoomId,
        reason,
    } = {}) => {
        try {
            const payload = {};

            if (Array.isArray(roomChanges) && roomChanges.length > 0) {
                payload.room_changes = roomChanges
                    .filter((change) => change && typeof change === 'object')
                    .map((change) => ({
                        booking_room_id: Number(change.booking_room_id),
                        requested_room_id: Number(change.requested_room_id),
                    }))
                    .filter((change) => Number.isFinite(change.booking_room_id) && Number.isFinite(change.requested_room_id));
            } else {
                payload.new_room_id = newRoomId;

                if (bookingRoomId) {
                    payload.booking_room_id = bookingRoomId;
                } else if (originalBookingRoomId) {
                    payload.original_booking_room_id = originalBookingRoomId;
                }
            }

            if (reason) {
                payload.reason = reason;
            }

            const response = await clientApi.post(`/bookings/${bookingId}/rebook`, payload);
            return response.data;
        } catch (error) {
            throw error;
        }
    },

    getFeedback: async (referenceNumber, signature = null, expires = null) => {
        try {
            const response = await clientApi.get(`/bookings/${referenceNumber}/feedback`, {
                params: {
                    ...(signature ? { signature } : {}),
                    ...(expires ? { expires } : {}),
                },
            });
            return response.data;
        } catch (error) {
            throw error;
        }
    },
};

export default clientBookingService;
