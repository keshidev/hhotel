<?php

namespace App\Enums;

enum NotificationType: string
{
    case BOOKING_CREATED = 'booking_created';
    case BOOKING_CONFIRMED = 'booking_confirmed';
    case BOOKING_CANCELLED = 'booking_cancelled';
    case BOOKING_MODIFIED = 'booking_modified';
    case PAYMENT_RECEIVED = 'payment_received';
    case PAYMENT_REJECTED = 'payment_rejected';
    case PAYMENT_PENDING_SUMMARY = 'payment_pending_summary';
    case PAYMENT_UNPAID_CHECKOUT = 'payment_unpaid_checkout';
    case PAYMENT_SUBMITTED = 'payment_submitted';
    case PAYMENT_PARTIAL = 'payment_partial';
    case ROOM_MAINTENANCE = 'room_maintenance';
    case ROOM_CONFLICT_BLOCKED = 'room_conflict_blocked';
    case ROOM_OVERBOOKING_BLOCKED = 'room_overbooking_blocked';
    case ROOM_READY = 'room_ready';
    case UPCOMING_CHECKIN = 'upcoming_checkin';
    case UPCOMING_CHECKOUT = 'upcoming_checkout';
    case EARLY_CHECKIN_REQUESTED = 'early_checkin_requested';
    case EARLY_CHECKIN_APPROVED = 'early_checkin_approved';
    case EARLY_CHECKIN_REJECTED = 'early_checkin_rejected';
    case GUEST_ARRIVING_TODAY = 'guest_arriving_today';
    case GUEST_LATE_CHECKIN = 'guest_late_checkin';
    case GUEST_CHECKING_OUT_TODAY = 'guest_checking_out_today';
    case GUEST_CHECKOUT_WITH_BALANCE = 'guest_checkout_with_balance';
    case STAFF_BOOKING_DELETED = 'staff_booking_deleted';
    case STAFF_BILLING_EDITED = 'staff_billing_edited';
    case STAFF_ROOM_OVERRIDE = 'staff_room_override';
    case REBOOKING_REQUESTED = 'rebooking_requested';
    case REBOOKING_APPROVED = 'rebooking_approved';
    case REBOOKING_REJECTED = 'rebooking_rejected';
    case WALKIN_CREATED = 'walkin_created';
    case DAILY_SUMMARY = 'daily_summary';
    case CANCELLATION_REQUESTED = 'cancellation_requested';
    case CANCELLATION_APPROVED = 'cancellation_approved';
    case CANCELLATION_REJECTED = 'cancellation_rejected';
    case ROOM_TRANSFER_REQUESTED = 'room_transfer_requested';
    case ROOM_TRANSFER_APPROVED = 'room_transfer_approved';
    case ROOM_TRANSFER_REJECTED = 'room_transfer_rejected';
    case ROOM_TRANSFER_COMPLETED = 'room_transfer_completed';
    case CONTACT_INQUIRY = 'contact_inquiry';
}
