<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ManualGcashSubmission;
use App\Models\Payment;
use App\Services\GuestPaymentAuthorizationService;
use App\Services\GuestBookingAccessSessionService;
use App\Services\PaymentAccessSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManualGcashResubmissionController extends Controller
{
    public function __construct(
        private PaymentAccessSessionService $paymentAccessSessions,
        private GuestPaymentAuthorizationService $guestPaymentAuthorization,
        private GuestBookingAccessSessionService $guestBookingAccessSessions
    ) {}

    public function redeem(Request $request, string $token)
    {
        $token = trim($token);

        if (strlen($token) < 40) {
            return $this->failureResponse($request);
        }

        $result = DB::transaction(function () use ($token) {
            $booking = Booking::with('primaryGuest')
                ->where('payment_bootstrap_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $booking || ! $this->canResubmit($booking)) {
                return null;
            }

            $accessToken = bin2hex(random_bytes(24));
            $this->paymentAccessSessions->store($booking->id, $accessToken);
            $booking->update([
                'payment_bootstrap_token_hash' => null,
                'payment_bootstrap_expires_at' => null,
            ]);

            return ['booking_id' => $booking->id, 'access_token' => $accessToken];
        }, 3);

        if (! $result) {
            return $this->failureResponse($request);
        }

        return redirect()->to($this->paymentUrl($result['booking_id']))
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ])
            ->withCookie($this->guestPaymentAuthorization->accessCookie(
                $request,
                $result['booking_id'],
                $result['access_token']
            ));
    }

    public function resume(Request $request, int $bookingId)
    {
        if (! $this->guestBookingAccessSessions->validate($request, $bookingId)) {
            return response()->json([
                'success' => false,
                'message' => 'Your secure booking session expired. Open My Bookings and search again.',
            ], 401);
        }

        $result = DB::transaction(function () use ($bookingId) {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();

            if (! $booking || ! $this->canResubmit($booking)) {
                return null;
            }

            $this->paymentAccessSessions->revoke($booking->id, 'manual_gcash_correction_reauthorized');
            $accessToken = bin2hex(random_bytes(24));
            $this->paymentAccessSessions->store($booking->id, $accessToken);
            $booking->update([
                'payment_bootstrap_token_hash' => null,
                'payment_bootstrap_expires_at' => null,
            ]);

            return ['booking_id' => $booking->id, 'access_token' => $accessToken];
        }, 3);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Payment proof resubmission is unavailable. The deadline may have passed or the booking details may not match.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'booking_id' => $result['booking_id'],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ])->withCookie($this->guestPaymentAuthorization->accessCookie(
            $request,
            $result['booking_id'],
            $result['access_token']
        ));
    }

    private function canResubmit(Booking $booking): bool
    {
        if ($booking->payment_bootstrap_expires_at && $booking->payment_bootstrap_expires_at->isPast()) {
            return false;
        }

        $payment = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('provider', 'manual_gcash')
            ->where('payment_status', 'pending')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $payment || ! $payment->payment_due_at?->isFuture()) {
            return false;
        }

        $isRebookingAdjustment = $payment->purpose === Payment::PURPOSE_REBOOKING_ADJUSTMENT;
        if ($isRebookingAdjustment ? $booking->booking_status !== 'confirmed' : $booking->booking_status !== 'pending') {
            return false;
        }

        $maxAttempts = max(1, (int) config('payment.manual_gcash.max_submission_attempts', 3));
        if ((int) $payment->submission_attempts >= $maxAttempts) {
            return false;
        }

        $latestStatus = ManualGcashSubmission::query()
            ->where('payment_id', $payment->id)
            ->latest('id')
            ->value('status');

        return $isRebookingAdjustment
            ? $latestStatus === null || $latestStatus === ManualGcashSubmission::STATUS_REJECTED
            : $latestStatus === ManualGcashSubmission::STATUS_REJECTED;
    }

    private function paymentUrl(int $bookingId): string
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $frontend.'/payment/'.$bookingId;
    }

    private function failureResponse(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'This payment correction link is invalid or has expired.',
            ], 422);
        }

        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return redirect()->to($frontend.'/my-booking?status=payment_link_expired');
    }
}
