<?php

use App\Http\Controllers\Admin\AmenityController;
use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\CancellationApprovalController;
use App\Http\Controllers\Admin\CmsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EarlyCheckInApprovalController;
use App\Http\Controllers\Admin\ManualGcashConfigurationController;
use App\Http\Controllers\Admin\OccupancyReportController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PromoCodeController;
use App\Http\Controllers\Admin\ReservationReportController;
use App\Http\Controllers\Admin\RevenueReportController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TransferApprovalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BookingRoomAssignmentController;
use App\Http\Controllers\Client\ClientBookingController;
use App\Http\Controllers\Client\ContactInquiryController as ClientContactInquiryController;
use App\Http\Controllers\Client\ManualGcashPaymentController;
use App\Http\Controllers\Client\ManualGcashResubmissionController;
use App\Http\Controllers\Client\PromoOfferController;
use App\Http\Controllers\ContactInquiryController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ManualGcashReconciliationController;
use App\Http\Controllers\ManualGcashReviewController;
use App\Http\Controllers\NoShowController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Receptionist\BookingController as ReceptionistBookingController;
use App\Http\Controllers\Receptionist\CancellationController;
use App\Http\Controllers\Receptionist\CancellationRequestController;
use App\Http\Controllers\Receptionist\EarlyCheckInRequestController;
use App\Http\Controllers\Receptionist\PaymentController;
use App\Http\Controllers\Receptionist\RebookingController;
use App\Http\Controllers\Receptionist\ReceptionistDashboardController;
use App\Http\Controllers\Receptionist\ReservationController;
use App\Http\Controllers\Receptionist\TransferRequestController;
use App\Http\Controllers\Receptionist\WalkInController;
use App\Http\Controllers\RoomCheckoutController;
use App\Http\Controllers\System\ManualGcashHealthController;
use App\Http\Controllers\System\SchedulerHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/system/scheduler-health', SchedulerHealthController::class)
    ->middleware('throttle:30,1')
    ->name('system.scheduler-health');

Route::get('/system/manual-gcash-health', ManualGcashHealthController::class)
    ->middleware('throttle:30,1')
    ->name('system.manual-gcash-health');

Route::get('/manual-gcash/resume/{token}', [ManualGcashResubmissionController::class, 'redeem'])
    ->middleware('throttle:manual-gcash-resume-link')
    ->name('manual-gcash.resume');

// ─────────────────────────────────────────────────────────────────────────────
// Public: Auth
// ─────────────────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:staff-login')
    ->name('api.login');


// ─────────────────────────────────────────────────────────────────────────────
// Retired payment gateway routes intentionally omitted.
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// Public: Client (guest-facing, no auth required)
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('client')->group(function () {

    Route::get('/promo-codes/offers', [PromoOfferController::class, 'index'])
        ->middleware('throttle:30,1');

    Route::post('/contact-inquiries', [ClientContactInquiryController::class, 'store'])
        ->middleware('throttle:contact-inquiries');

    // Room availability
    Route::get('/rooms/availability-calendar', [ClientBookingController::class, 'getAvailabilityCalendar'])
        ->middleware('online-booking.available');

    Route::get('/rooms/available', [ClientBookingController::class, 'getAvailableRooms'])
        ->middleware('online-booking.available')
        ->when(config('bookings.throttle.rooms_available'), function ($route, $middleware) {
            return $route->middleware($middleware);
        });

    Route::get('/room-types/{slug}', [ClientBookingController::class, 'getRoomTypeDetails'])
        ->when(config('bookings.throttle.rooms_available'), function ($route, $middleware) {
            return $route->middleware($middleware);
        });

    // Booking creation
    Route::post('/bookings', [ClientBookingController::class, 'store'])
        ->middleware(['online-booking.available', 'throttle:client-bookings']);

    // Booking status lookup
    Route::post('/bookings/check-status', [ClientBookingController::class, 'checkBookingStatus'])
        ->when(config('bookings.throttle.booking_status'), function ($route, $middleware) {
            return $route->middleware($middleware);
        });

    // Guest cancellation & rebooking
    Route::post('/bookings/{id}/cancel', [ClientBookingController::class, 'cancel'])
        ->middleware('throttle:10,1');

    Route::post('/bookings/{id}/rebook', [ClientBookingController::class, 'rebook'])
        ->middleware('throttle:10,1');

    // Manual GCash payment and proof submission
    Route::post('/manual-gcash/{bookingId}/prepare', [ManualGcashPaymentController::class, 'prepare'])
        ->middleware('throttle:manual-gcash-prepare');
    Route::get('/manual-gcash/{bookingId}/status', [ManualGcashPaymentController::class, 'status'])
        ->middleware('throttle:30,1');
    Route::get('/manual-gcash/{bookingId}/qr', [ManualGcashPaymentController::class, 'qr'])
        ->middleware('throttle:30,1');
    Route::post('/manual-gcash/{bookingId}/proof', [ManualGcashPaymentController::class, 'submit'])
        ->middleware('throttle:manual-gcash-proof');
    Route::post('/manual-gcash/{bookingId}/resume', [ManualGcashResubmissionController::class, 'resume'])
        ->middleware('throttle:manual-gcash-resume');

    // Auth (password reset)
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-recovery-request')
        ->name('api.forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-recovery-reset')
        ->name('api.reset-password');

    // Promo code preview — public so guests can validate before booking
    Route::post('/promo-codes/validate', [PromoCodeController::class, 'validateCode'])
        ->middleware('throttle:20,1');

    // ── Feedback (token-based, no auth needed) ────────────────────────────
    Route::get('/feedback/{token}', [FeedbackController::class, 'show'])
        ->middleware('throttle:feedback-view');
    Route::post('/feedback/{token}', [FeedbackController::class, 'submit'])
        ->middleware('throttle:feedback-submit');
    Route::get('/bookings/{reference}/feedback', [FeedbackController::class, 'getByBooking'])
        ->middleware('signed')
        ->name('client.feedback.booking-status');

    // ── CMS (public — no auth needed) ─────────────────────────────────────
    Route::get('/cms', [CmsController::class, 'public']);
    Route::get('/featured-reviews', [FeedbackController::class, 'featuredReviews'])
        ->middleware('throttle:feedback-public');
    Route::get('/reviews', [FeedbackController::class, 'publicReviews'])
        ->middleware('throttle:feedback-public');

});

// ─────────────────────────────────────────────────────────────────────────────
// Protected: requires auth:sanctum
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'staff.active'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/bookings/{id}/no-show', [NoShowController::class, 'markAsNoShow'])
        ->middleware('role:admin,receptionist');
    Route::get('/bookings/{id}/assignable-rooms', [BookingRoomAssignmentController::class, 'assignableRooms'])
        ->middleware('role:admin,receptionist');
    Route::post('/bookings/{id}/assign-room', [BookingRoomAssignmentController::class, 'assignRoom'])
        ->middleware('role:admin,receptionist');
    Route::post('/bookings/{bookingId}/rooms/{roomId}/checkout', [RoomCheckoutController::class, 'checkout'])
        ->middleware('role:admin,receptionist');
    Route::post('/bookings/{bookingId}/rooms/{roomId}/extend', [RoomCheckoutController::class, 'extend'])
        ->middleware('role:admin,receptionist');

    Route::prefix('payment-records')
        ->middleware('role:admin,receptionist')
        ->group(function () {
            Route::get('/', [PaymentController::class, 'index']);
            Route::get('/{id}', [PaymentController::class, 'show']);
        });

    Route::prefix('contact-inquiries')
        ->middleware('role:admin,receptionist')
        ->group(function () {
            Route::get('/', [ContactInquiryController::class, 'index']);
            Route::get('/{contactInquiry}', [ContactInquiryController::class, 'show']);
            Route::patch('/{contactInquiry}/status', [ContactInquiryController::class, 'updateStatus'])
                ->middleware('throttle:30,1');
        });

    Route::prefix('manual-gcash-reviews')
        ->middleware('role:admin,receptionist')
        ->group(function () {
            Route::get('/', [ManualGcashReviewController::class, 'index']);
            Route::get('/{submission}', [ManualGcashReviewController::class, 'show']);
            Route::get('/{submission}/proof', [ManualGcashReviewController::class, 'proof'])
                ->middleware('throttle:60,1');
            Route::post('/{submission}/approve', [ManualGcashReviewController::class, 'approve'])
                ->middleware('throttle:20,1');
            Route::post('/{submission}/reject', [ManualGcashReviewController::class, 'reject'])
                ->middleware('throttle:20,1');
        });

    Route::prefix('manual-gcash-reconciliations')
        ->middleware('role:admin,receptionist')
        ->group(function () {
            Route::get('/summary', [ManualGcashReconciliationController::class, 'summary']);
            Route::get('/', [ManualGcashReconciliationController::class, 'index']);
            Route::post('/submissions/{submission}/match', [ManualGcashReconciliationController::class, 'match'])
                ->middleware('throttle:30,1');
            Route::post('/submissions/{submission}/exception', [ManualGcashReconciliationController::class, 'flagException'])
                ->middleware('throttle:30,1');
            Route::post('/{reconciliation}/resolve', [ManualGcashReconciliationController::class, 'resolve'])
                ->middleware(['role:admin', 'throttle:20,1']);
        });

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/unread-count', 'unreadCount');
        Route::patch('/read-all', 'markAllAsRead');
        Route::patch('/{id}/read', 'markAsRead');
        Route::delete('/{id}', 'destroy');
    });

    // ── Admin ─────────────────────────────────────────────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/revenue-chart', [DashboardController::class, 'revenueChart']);

        // Reports
        Route::get('/reports/revenue', [RevenueReportController::class,     'index']);
        Route::get('/reports/occupancy', [OccupancyReportController::class,   'index']);
        Route::get('/reports/reservations', [ReservationReportController::class, 'index']);
        Route::get('/reports/reservations/modified', [ReservationReportController::class, 'modified']);

        // Users
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('throttle:20,1');
        Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('throttle:20,1');
        Route::post('/users/bulk-delete', [UserController::class, 'bulkDelete'])->middleware('throttle:10,1');

        // Rooms
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::get('/rooms/{room}', [RoomController::class, 'show']);
        Route::put('/rooms/{room}', [RoomController::class, 'update']);
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
        Route::patch('/rooms/{room}/status', [RoomController::class, 'updateStatus']);
        Route::get('/rooms/types/list', [RoomController::class, 'getRoomTypes']);
        Route::get('/rooms/floors/list', [RoomController::class, 'getFloors']);
        Route::post('/rooms/bulk-delete', [RoomController::class, 'bulkDelete']);

        // Amenities
        Route::get('/amenities', [AmenityController::class, 'index']);
        Route::post('/amenities', [AmenityController::class, 'store']);
        Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

        // Profile
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
        Route::put('/profile/notification-preferences', [ProfileController::class, 'updateNotificationPreferences']);
        Route::get('/profile/activity-log', [ProfileController::class, 'getActivityLog']);

        // Settings
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::get('/settings/mail-status', [SettingsController::class, 'mailStatus']);
        Route::post('/settings/send-test-email', [SettingsController::class, 'sendTestEmail'])
            ->middleware('throttle:3,1');
        Route::get('/settings/manual-gcash', [ManualGcashConfigurationController::class, 'show']);
        Route::post('/settings/manual-gcash', [ManualGcashConfigurationController::class, 'update'])
            ->middleware('throttle:10,1');
        Route::get('/settings/manual-gcash/qr', [ManualGcashConfigurationController::class, 'qr'])
            ->middleware('throttle:60,1');
        Route::delete('/settings/manual-gcash', [ManualGcashConfigurationController::class, 'destroy'])
            ->middleware('throttle:5,1');

        // Audit Trail
        Route::get('/audit-trail', [AuditTrailController::class, 'index']);
        Route::get('/audit-trail/export', [AuditTrailController::class, 'export']);
        Route::get('/audit-trail/{id}', [AuditTrailController::class, 'show']);

        // Promo Codes
        // IMPORTANT: static routes (stats, validate) MUST come BEFORE
        // the {promoCode} wildcard route to avoid model binding conflicts.
        Route::get('/promo-codes', [PromoCodeController::class, 'index']);
        Route::post('/promo-codes', [PromoCodeController::class, 'store']);
        Route::get('/promo-codes/stats', [PromoCodeController::class, 'stats']);
        Route::post('/promo-codes/validate', [PromoCodeController::class, 'validateCode']);
        Route::get('/promo-codes/{promoCode}', [PromoCodeController::class, 'show']);
        Route::put('/promo-codes/{promoCode}', [PromoCodeController::class, 'update']);
        Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy']);
        Route::patch('/promo-codes/{promoCode}/toggle', [PromoCodeController::class, 'toggleStatus']);

        // Cancellation Approvals
        Route::get('/cancellation-requests', [CancellationApprovalController::class, 'index']);
        Route::get('/cancellation-requests/{id}', [CancellationApprovalController::class, 'show']);
        Route::post('/cancellation-requests/{id}/approve', [CancellationApprovalController::class, 'approve']);
        Route::post('/cancellation-requests/{id}/reject', [CancellationApprovalController::class, 'reject']);
        Route::post('/cancellation-requests/{id}/refund', [CancellationApprovalController::class, 'processRefund']);
        Route::get('/cancellation-requests/{id}/refund-proof', [CancellationApprovalController::class, 'refundProof']);
        Route::post('/cancellation-requests/{id}/finalize', [CancellationApprovalController::class, 'finalize']);

        // Rebooking approvals and financial adjustments
        Route::get('/rebookings', [RebookingController::class, 'index']);
        Route::get('/rebookings/{id}', [RebookingController::class, 'show']);
        Route::post('/rebookings/{id}/approve', [RebookingController::class, 'approve']);
        Route::post('/rebookings/{id}/reject', [RebookingController::class, 'reject']);
        Route::post('/rebookings/{id}/request-payment', [RebookingController::class, 'requestAdditionalPayment']);
        Route::post('/rebookings/{id}/refund-review', [RebookingController::class, 'sendForRefundReview']);
        Route::post('/rebookings/{id}/refund', [RebookingController::class, 'processRefund']);
        Route::get('/rebookings/{id}/refund-proof', [RebookingController::class, 'refundProof']);

        // Room Transfer Approvals
        Route::get('/transfer-requests', [TransferApprovalController::class, 'index']);
        Route::get('/transfer-requests/{id}', [TransferApprovalController::class, 'show']);
        Route::post('/transfer-requests/{id}/approve', [TransferApprovalController::class, 'approve']);
        Route::post('/transfer-requests/{id}/reject', [TransferApprovalController::class, 'reject']);
        Route::post('/transfer-requests/{id}/complete', [TransferApprovalController::class, 'complete']);

        // Early Check-In Approvals
        Route::get('/early-check-in-requests', [EarlyCheckInApprovalController::class, 'index']);
        Route::get('/early-check-in-requests/{id}', [EarlyCheckInApprovalController::class, 'show']);
        Route::post('/early-check-in-requests/{id}/approve', [EarlyCheckInApprovalController::class, 'approve'])
            ->middleware('throttle:20,1');
        Route::post('/early-check-in-requests/{id}/reject', [EarlyCheckInApprovalController::class, 'reject'])
            ->middleware('throttle:20,1');

        // Feedback report (admin only)
        Route::get('/feedbacks', [FeedbackController::class, 'index']);
        Route::post('/feedbacks/{id}/reply', [FeedbackController::class, 'reply']);

        // CMS (admin only)
        Route::get('/cms', [CmsController::class, 'index']);
        Route::put('/cms', [CmsController::class, 'update'])->middleware('throttle:20,1');
        Route::post('/cms/upload-image', [CmsController::class, 'uploadImage'])->middleware('throttle:10,1');
        Route::patch('/feedbacks/{id}/feature', [FeedbackController::class, 'toggleFeature']);

    });

    // ── Receptionist (strict receptionist role only) ─────────────────────────
    Route::prefix('receptionist')->middleware('role:receptionist')->group(function () {

        // Dashboard
        Route::get('/dashboard', [ReceptionistDashboardController::class, 'index']);
        Route::get('/dashboard/quick-stats', [ReceptionistDashboardController::class, 'getQuickStats']);

        // Walk-In
        Route::get('/rooms/available', [WalkInController::class, 'availableRooms']);
        Route::post('/walk-in', [WalkInController::class, 'store']);

        // Bookings
        Route::prefix('bookings')->group(function () {
            Route::get('/', [ReceptionistBookingController::class, 'index']);
            Route::get('/stats', [ReceptionistBookingController::class, 'getStats']);
            Route::get('/{id}', [ReceptionistBookingController::class, 'show']);
            Route::post('/{id}/confirm', [ReceptionistBookingController::class, 'confirm']);
            Route::post('/{id}/reject', [ReceptionistBookingController::class, 'reject']);
            Route::post('/{id}/check-in', [ReceptionistBookingController::class, 'checkIn']);
            Route::post('/{id}/early-check-in-request', [EarlyCheckInRequestController::class, 'store'])
                ->middleware('throttle:10,1');
            Route::post('/{id}/settle-balance', [ReceptionistBookingController::class, 'settleBalance']);
            Route::post('/{id}/check-out', [ReceptionistBookingController::class, 'checkOut']);
            Route::post('/{id}/extra-charges', [ReservationController::class, 'addExtraCharges']);
        });

        // Reservations
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{id}', [ReservationController::class, 'show']);
        // Canonical lifecycle transitions are handled by BookingController
        // to prevent logic drift between booking and reservation endpoints.
        Route::post('/reservations/{id}/check-in', [ReceptionistBookingController::class, 'checkIn']);
        Route::post('/reservations/{id}/check-out', [ReceptionistBookingController::class, 'checkOut']);
        Route::get('/reservations/{id}/transfer-rooms', [ReservationController::class, 'getTransferRooms']);

        // Payments
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{id}', [PaymentController::class, 'show']);
        Route::post('/payments/{id}/accept', [PaymentController::class, 'accept']);
        Route::post('/payments/{id}/reject', [PaymentController::class, 'reject']);

        // Cancellations
        Route::get('/cancellations', [CancellationController::class, 'index']);
        Route::get('/cancellations/{id}', [CancellationController::class, 'show']);

        // Cancellation Requests (approval workflow)
        Route::get('/cancellation-requests', [CancellationRequestController::class, 'index']);
        Route::get('/cancellation-requests/{id}', [CancellationRequestController::class, 'show']);

        // Room Transfer Requests (approval workflow)
        Route::get('/transfer-requests', [TransferRequestController::class, 'index']);
        Route::get('/transfer-requests/{id}', [TransferRequestController::class, 'show']);
        Route::post('/transfer-requests', [TransferRequestController::class, 'store']);

        // Rebookings
        Route::get('/rebookings', [RebookingController::class, 'index']);
        Route::get('/rebookings/{id}', [RebookingController::class, 'show']);
        Route::post('/rebookings/{id}/approve', [RebookingController::class, 'approve']);
        Route::post('/rebookings/{id}/reject', [RebookingController::class, 'reject']);
        Route::post('/rebookings/{id}/request-payment', [RebookingController::class, 'requestAdditionalPayment']);
        Route::post('/rebookings/{id}/refund-review', [RebookingController::class, 'sendForRefundReview']);

        // Promo code validation for walk-in bookings
        Route::post('/promo-codes/validate', [PromoCodeController::class, 'validateCode']);

        // Profile
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
        Route::put('/profile/notification-preferences', [ProfileController::class, 'updateNotificationPreferences']);
    });
});
