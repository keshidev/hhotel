<?php

namespace App\Helpers;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

class NotificationHelper
{
    // CORE
  

    public static function send(int $userId, NotificationType|string $type, string $title, string $message, array $data = []): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => $type instanceof NotificationType ? $type->value : $type,
            'title'   => $title,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    private static function getAdminIds(): array
    {
        return User::where('role', 'admin')->pluck('id')->toArray();
    }

    private static function getReceptionistIds(): array
    {
        return User::where('role', 'receptionist')->pluck('id')->toArray();
    }

    private static function sendToMany(array $userIds, NotificationType|string $type, string $title, string $message, array $data = []): void
    {
        foreach ($userIds as $userId) {
            self::send($userId, $type, $title, $message, $data);
        }
    }


    // BOOKING NOTIFICATIONS

    /**
     * New reservation created.
     * Notify: Admin + Receptionist
     * Call from: booking or walk-in store
     */
    public static function bookingCreated(array $booking): void
    {
        $title   = 'New Reservation';
        $message = "New booking by {$booking['guest_name']} (Room {$booking['room']}, {$booking['check_in']} – {$booking['check_out']}).";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'check_in'   => $booking['check_in'],
            'check_out'  => $booking['check_out'],
            'amount'     => $booking['amount'] ?? null,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::BOOKING_CREATED, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::BOOKING_CREATED, $title, $message, $data);
    }

    /**
     * Reservation cancelled.
     * Notify: Admin + Receptionist
     * Call from: cancel controller
     */
    public static function bookingCancelled(array $booking, ?string $reason = null, bool $includeReceptionists = true): void
    {
        $title   = 'Booking Cancelled';
        $message = "Booking {$booking['id']} by {$booking['guest_name']} (Room {$booking['room']}) has been cancelled."
                 . ($reason ? " Reason: {$reason}" : '');
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'reason'     => $reason,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::BOOKING_CANCELLED, $title, $message, $data);
        if ($includeReceptionists) {
            self::sendToMany(self::getReceptionistIds(), NotificationType::BOOKING_CANCELLED, $title, $message, $data);
        }
    }

    /**
     * Reservation modified.
     * Notify: Admin + Receptionist
     * Call from: booking update
     */
    public static function bookingModified(array $booking, string $changedFields): void
    {
        $title   = 'Reservation Modified';
        $message = "Booking {$booking['id']} ({$booking['guest_name']}) was updated: {$changedFields}.";
        $data    = [
            'booking_id'     => $booking['id'],
            'guest_name'     => $booking['guest_name'],
            'room'           => $booking['room'],
            'check_in'       => $booking['check_in'],
            'check_out'      => $booking['check_out'],
            'changed_fields' => $changedFields,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::BOOKING_MODIFIED, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::BOOKING_MODIFIED, $title, $message, $data);
    }


    // PAYMENT NOTIFICATIONS

    /**
     * Payment accepted/received.
     * Notify: Admin + Receptionist
     * Call from: PaymentController::accept()
     */
    public static function paymentReceived(array $payment, bool $includeReceptionists = true): void
    {
        $title   = 'Payment Received';
        $message = "{$payment['amount']} received from {$payment['guest']} via {$payment['method']} (Ref: {$payment['id']}).";
        $data    = [
            'payment_id' => $payment['id'],
            'booking_id' => $payment['booking_id'] ?? null,
            'guest_name' => $payment['guest']       ?? null,
            'amount'     => $payment['amount'],
            'method'     => $payment['method'],
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::PAYMENT_RECEIVED, $title, $message, $data);
        if ($includeReceptionists) {
            self::sendToMany(self::getReceptionistIds(), NotificationType::PAYMENT_RECEIVED, $title, $message, $data);
        }
    }

    /**
     * Payment rejected by receptionist.
     * Notify: Admin only (receptionist did the action, admin must be informed)
     * Call from: PaymentController::reject()
     */
    public static function paymentRejected(array $payment, ?string $reason = null): void
    {
        $staffName = $payment['staff_name'] ?? 'Receptionist';
        $title     = 'Payment Rejected';
        $message   = "{$staffName} rejected payment {$payment['id']} from {$payment['guest_name']}"
                   . " ({$payment['amount']} via {$payment['method']})."
                   . ($reason ? " Reason: {$reason}" : '');
        $data = [
            'payment_id' => $payment['id'],
            'booking_id' => $payment['booking_id'] ?? null,
            'guest_name' => $payment['guest_name'],
            'amount'     => $payment['amount'],
            'method'     => $payment['method'],
            'reason'     => $reason,
            'staff_name' => $staffName,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::PAYMENT_REJECTED, $title, $message, $data);
    }

    /**
     * Payment submitted for verification.
     * Notify: Receptionist only
     * Call from: payment store
     */
    public static function paymentSubmitted(array $payment): void
    {
        $title   = 'New Payment Submitted';
        $message = "{$payment['guest']} submitted payment proof ({$payment['amount']} via {$payment['method']}) for {$payment['booking_id']}.";
        $data    = [
            'payment_id' => $payment['id'],
            'booking_id' => $payment['booking_id'],
            'guest_name' => $payment['guest'],
            'amount'     => $payment['amount'],
            'method'     => $payment['method'],
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::PAYMENT_SUBMITTED, $title, $message, $data);
    }

    /**
     * Partial payment detected.
     * Notify: Receptionist
     * Call when: amount < total
     */
    public static function paymentPartial(array $payment, string $balance): void
    {
        $title   = 'Partial Payment';
        $message = "{$payment['guest']} paid {$payment['amount']} for {$payment['booking_id']}. Remaining balance: {$balance}.";
        $data    = [
            'payment_id' => $payment['id'],
            'booking_id' => $payment['booking_id'],
            'guest_name' => $payment['guest'],
            'amount'     => $payment['amount'],
            'balance'    => $balance,
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::PAYMENT_PARTIAL, $title, $message, $data);
    }

    /**
     * Daily pending payment summary.
     * Notify: Admin + Receptionist
     * Run via: scheduler
     */
    public static function paymentPendingSummary(int $pendingCount): void
    {
        if ($pendingCount === 0) return;

        $title   = 'Pending Payment Summary';
        $message = "{$pendingCount} payment(s) are awaiting verification as of today.";
        $data    = ['pending_count' => $pendingCount];

        self::sendToMany(self::getAdminIds(), NotificationType::PAYMENT_PENDING_SUMMARY, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::PAYMENT_PENDING_SUMMARY, $title, $message, $data);
    }

    /**
     * Unpaid checkout attempt.
     * Notify: Admin + Receptionist
     * Call from: checkout validation
     */
    public static function paymentUnpaidCheckout(array $booking, string $balance): void
    {
        $title   = 'Unpaid Check-out Attempt';
        $message = "{$booking['guest_name']} (Room {$booking['room']}, {$booking['id']}) is trying to check out with balance of {$balance}.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'amount'     => $balance,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::PAYMENT_UNPAID_CHECKOUT, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::PAYMENT_UNPAID_CHECKOUT, $title, $message, $data);
    }


    // ROOM & OPERATIONS NOTIFICATIONS

    /**
     * Room marked as under maintenance.
     * Notify: Admin + Receptionist
     * Call when: status = maintenance
     */
    public static function roomMaintenance(string $roomNumber, string $reason = ''): void
    {
        $title   = 'Room Under Maintenance';
        $message = "Room {$roomNumber} has been marked as Under Maintenance." . ($reason ? " Reason: {$reason}" : '');
        $data    = ['room' => $roomNumber, 'reason' => $reason];

        self::sendToMany(self::getAdminIds(), NotificationType::ROOM_MAINTENANCE, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::ROOM_MAINTENANCE, $title, $message, $data);
    }

    /**
     * Room ready for check-in.
     * Notify: Receptionist only
     * Call when: status = available
     */
    public static function roomReady(string $roomNumber): void
    {
        $title   = 'Room Ready';
        $message = "Room {$roomNumber} is now clean and ready for check-in.";
        $data    = ['room' => $roomNumber];

        self::sendToMany(self::getReceptionistIds(), NotificationType::ROOM_READY, $title, $message, $data);
    }

    /**
     * Double booking blocked.
     * Notify: Admin only
     * Call when: conflict detected
     */
    public static function roomConflictBlocked(string $roomNumber, array $booking): void
    {
        $title   = 'Room Conflict Blocked';
        $message = "Double-booking attempt blocked for Room {$roomNumber} on {$booking['check_in']} – {$booking['check_out']} (attempted by {$booking['guest_name']}).";
        $data    = [
            'room'       => $roomNumber,
            'guest_name' => $booking['guest_name'],
            'check_in'   => $booking['check_in'],
            'check_out'  => $booking['check_out'],
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::ROOM_CONFLICT_BLOCKED, $title, $message, $data);
    }

    /**
     * Overbooking blocked.
     * Notify: Admin only
     * Call when: no rooms available
     */
    public static function roomOverbookingBlocked(array $booking): void
    {
        $title   = 'Overbooking Blocked';
        $message = "Overbooking attempt blocked for {$booking['check_in']} – {$booking['check_out']} (attempted by {$booking['guest_name']}). No rooms available.";
        $data    = [
            'guest_name' => $booking['guest_name'],
            'check_in'   => $booking['check_in'],
            'check_out'  => $booking['check_out'],
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::ROOM_OVERBOOKING_BLOCKED, $title, $message, $data);
    }


    // ARRIVAL / DEPARTURE NOTIFICATIONS

    /**
     * Guest arriving today.
     * Notify: Receptionist
     * Run via: scheduler
     */
    public static function guestArrivingToday(array $booking): void
    {
        $title   = 'Guest Arriving Today';
        $message = "{$booking['guest_name']} is checking into Room {$booking['room']} today ({$booking['check_in']}).";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'check_in'   => $booking['check_in'],
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::GUEST_ARRIVING_TODAY, $title, $message, $data);
    }

    /**
     * Late check-in alert.
     * Notify: Receptionist
     * Run at: 6pm via scheduler
     */
    public static function guestLateCheckin(array $booking): void
    {
        $title   = 'Late Check-in Alert';
        $message = "{$booking['guest_name']} (Room {$booking['room']}, {$booking['id']}) has not checked in yet. It is past 6 PM.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::GUEST_LATE_CHECKIN, $title, $message, $data);
    }

    /**
     * Guest checking out today.
     * Notify: Receptionist
     * Run via: scheduler
     */
    public static function guestCheckingOutToday(array $booking): void
    {
        $title   = 'Guest Checking Out Today';
        $message = "{$booking['guest_name']} (Room {$booking['room']}, {$booking['id']}) is checking out today.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'check_out'  => $booking['check_out'],
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::GUEST_CHECKING_OUT_TODAY, $title, $message, $data);
    }

    /**
     * Checkout with outstanding balance.
     * Notify: Receptionist
     * Call from: checkout
     */
    public static function guestCheckoutWithBalance(array $booking, string $balance): void
    {
        $title   = 'Checkout Balance Due';
        $message = "{$booking['guest_name']} (Room {$booking['room']}) has an outstanding balance of {$balance} before checking out.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'amount'     => $balance,
        ];

        self::sendToMany(self::getReceptionistIds(), NotificationType::GUEST_CHECKOUT_WITH_BALANCE, $title, $message, $data);
    }


    // WALK-IN NOTIFICATIONS

    /**
     * New walk-in created.
     * Notify: Admin + Receptionist
     * Call from: walk-in store
     */
    public static function walkinCreated(array $booking): void
    {
        $title   = 'New Walk-in';
        $message = "Walk-in guest {$booking['guest_name']} registered for Room {$booking['room']} ({$booking['check_in']} – {$booking['check_out']}).";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'check_in'   => $booking['check_in'],
            'check_out'  => $booking['check_out'],
            'amount'     => $booking['amount'] ?? null,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::WALKIN_CREATED, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::WALKIN_CREATED, $title, $message, $data);
    }


    // REBOOKING NOTIFICATIONS

    /**
     * Rebooking requested.
     * Notify: Admin + Receptionist
     * Call from: rebooking store
     */
    public static function rebookingRequested(array $rebooking): void
    {
        $title   = 'Rebooking Requested';
        $message = "{$rebooking['guest_name']} requested a rebooking for {$rebooking['booking_id']} (new dates: {$rebooking['new_check_in']} – {$rebooking['new_check_out']}).";
        $data    = [
            'booking_id' => $rebooking['booking_id'],
            'guest_name' => $rebooking['guest_name'],
            'room'       => $rebooking['room'] ?? null,
            'check_in'   => $rebooking['new_check_in'],
            'check_out'  => $rebooking['new_check_out'],
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::REBOOKING_REQUESTED, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::REBOOKING_REQUESTED, $title, $message, $data);
    }

    /**
     * Cancellation requested and awaiting approval.
     * Notify: Admin + Receptionist
     */
    public static function cancellationRequested(array $request): void
    {
        $title = 'Cancellation Requested';
        $message = "{$request['guest_name']} requested cancellation for {$request['booking_id']}."
            . (!empty($request['reason']) ? " Reason: {$request['reason']}" : '');

        $data = [
            'request_id' => $request['request_id'] ?? null,
            'booking_id' => $request['booking_id'],
            'guest_name' => $request['guest_name'],
            'room' => $request['room'] ?? null,
            'check_in' => $request['check_in'] ?? null,
            'check_out' => $request['check_out'] ?? null,
            'reason' => $request['reason'] ?? null,
            'refund_amount' => $request['refund_amount'] ?? null,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::CANCELLATION_REQUESTED, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), NotificationType::CANCELLATION_REQUESTED, $title, $message, $data);
    }

    /**
     * Cancellation request decision posted by staff.
     * Notify: Admin + Receptionist
     */
    public static function cancellationReviewed(array $request, bool $approved): void
    {
        $type = $approved ? 'cancellation_approved' : 'cancellation_rejected';
        $title = $approved ? 'Cancellation Approved' : 'Cancellation Rejected';
        $message = "{$request['booking_id']} cancellation request was "
            . ($approved ? 'approved' : 'rejected')
            . " by {$request['reviewed_by']}."
            . (!empty($request['decision_note']) ? " Note: {$request['decision_note']}" : '');

        $data = [
            'request_id' => $request['request_id'] ?? null,
            'booking_id' => $request['booking_id'],
            'guest_name' => $request['guest_name'] ?? null,
            'reviewed_by' => $request['reviewed_by'],
            'decision_note' => $request['decision_note'] ?? null,
            'status' => $approved ? 'approved' : 'rejected',
        ];

        self::sendToMany(self::getAdminIds(), $type, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), $type, $title, $message, $data);
    }

    /**
     * Rebooking request decision posted by staff.
     * Notify: Admin + Receptionist
     */
    public static function rebookingReviewed(array $rebooking, bool $approved): void
    {
        $type = $approved ? 'rebooking_approved' : 'rebooking_rejected';
        $title = $approved ? 'Rebooking Approved' : 'Rebooking Rejected';
        $message = "{$rebooking['booking_id']} rebooking request was "
            . ($approved ? 'approved' : 'rejected')
            . " by {$rebooking['reviewed_by']}."
            . (!empty($rebooking['decision_note']) ? " Note: {$rebooking['decision_note']}" : '');

        $data = [
            'rebooking_id' => $rebooking['rebooking_id'] ?? null,
            'booking_id' => $rebooking['booking_id'],
            'guest_name' => $rebooking['guest_name'] ?? null,
            'reviewed_by' => $rebooking['reviewed_by'],
            'decision_note' => $rebooking['decision_note'] ?? null,
            'status' => $approved ? 'approved' : 'rejected',
        ];

        self::sendToMany(self::getAdminIds(), $type, $title, $message, $data);
        self::sendToMany(self::getReceptionistIds(), $type, $title, $message, $data);
    }


    // STAFF ACTIVITY MONITORING (Admin only)
    

    /**
     * Booking deleted by staff.
     * Notify: Admin only
     */
    public static function staffBookingDeleted(string $staffName, array $booking): void
    {
        $title   = 'Booking Deleted by Staff';
        $message = "{$staffName} deleted booking {$booking['id']} ({$booking['guest_name']}, Room {$booking['room']}).";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'],
            'room'       => $booking['room'],
            'staff_name' => $staffName,
            'action'     => 'Booking deleted',
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::STAFF_BOOKING_DELETED, $title, $message, $data);
    }

    /**
     * Billing edited by staff.
     * Notify: Admin only
     */
    public static function staffBillingEdited(string $staffName, array $booking, string $oldAmount, string $newAmount): void
    {
        $title   = 'Billing Amount Edited';
        $message = "{$staffName} changed billing for {$booking['id']} from {$oldAmount} to {$newAmount}.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'] ?? null,
            'staff_name' => $staffName,
            'action'     => "Billing changed: {$oldAmount} → {$newAmount}",
            'amount'     => $newAmount,
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::STAFF_BILLING_EDITED, $title, $message, $data);
    }

    /**
     * Room assignment overridden by staff.
     * Notify: Admin only
     */
    public static function staffRoomOverride(string $staffName, array $booking, string $oldRoom, string $newRoom): void
    {
        $title   = 'Room Assignment Overridden';
        $message = "{$staffName} changed room assignment for {$booking['id']} from Room {$oldRoom} to Room {$newRoom}.";
        $data    = [
            'booking_id' => $booking['id'],
            'guest_name' => $booking['guest_name'] ?? null,
            'staff_name' => $staffName,
            'room'       => $newRoom,
            'action'     => "Room changed: {$oldRoom} → {$newRoom}",
        ];

        self::sendToMany(self::getAdminIds(), NotificationType::STAFF_ROOM_OVERRIDE, $title, $message, $data);
    }


    // DAILY SUMMARY (Admin only)

    /**
     * Once-daily summary of operations.
     * Notify: Admin only
     * Call from: app/Console/Commands/SendDailyNotifications.php (scheduled at e.g. 8 AM)
     *
     * @param array $summary Keys: checkins, checkouts, in_house, revenue, pending_payments
     */
    public static function dailySummary(array $summary): void
    {
        $title   = 'Daily Summary — ' . now()->format('F j, Y');
        $message = "Today: {$summary['checkins']} check-in(s), {$summary['checkouts']} check-out(s), "
                 . "{$summary['in_house']} guest(s) in-house. Revenue: {$summary['revenue']}. "
                 . "Pending payments: {$summary['pending_payments']}.";
        $data    = $summary;

        self::sendToMany(self::getAdminIds(), NotificationType::DAILY_SUMMARY, $title, $message, $data);
    }
}
