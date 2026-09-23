<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Services\EarlyCheckInApprovalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class EarlyCheckInRequestController extends Controller
{
    public function __construct(private EarlyCheckInApprovalService $approvalService)
    {
    }

    public function store(Request $request, $booking)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $earlyRequest = $this->approvalService->create(
                bookingKey: $booking,
                reason: $validated['reason'],
                requester: $request->user()
            );
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'success' => true,
            'code' => 'EARLY_APPROVAL_PENDING',
            'message' => 'Early check-in request sent to an administrator. Wait for approval before checking in the guest.',
            'request' => [
                'id' => $earlyRequest->id,
                'status' => $earlyRequest->status,
                'expires_at' => $earlyRequest->expires_at?->toDateTimeString(),
            ],
        ], 201);
    }

    private function errorResponse(\RuntimeException $exception)
    {
        $message = $exception->getMessage();

        if (str_starts_with($message, 'CHECKIN_DATE_NOT_YET:')) {
            $date = substr($message, strlen('CHECKIN_DATE_NOT_YET:'));
            return response()->json([
                'success' => false,
                'message' => "Early check-in cannot be requested before the booked check-in date ({$date}).",
            ], 422);
        }

        $payload = match ($message) {
            'BOOKING_NOT_CONFIRMED' => ['Only confirmed bookings can request early check-in.', 409, null],
            'EARLY_WINDOW_CLOSED' => ['Official check-in time has arrived. The receptionist may check in normally.', 409, null],
            'OPEN_REQUEST_EXISTS' => ['An early check-in request is already waiting for administrator approval.', 409, 'EARLY_APPROVAL_PENDING'],
            'REQUEST_ALREADY_APPROVED' => ['Early check-in is already approved. Press Check In again to complete it.', 409, 'EARLY_APPROVAL_APPROVED'],
            'REASON_REQUIRED' => ['Enter a clear reason using at least 10 characters.', 422, null],
            default => ['Unable to submit the early check-in request.', 422, null],
        };

        if (str_starts_with($message, 'BOOKING_LIFECYCLE_LOCKED:')) {
            $payload = ['Early check-in is blocked while a cancellation request is in progress.', 409, null];
        }

        return response()->json(array_filter([
            'success' => false,
            'message' => $payload[0],
            'code' => $payload[2],
        ], fn ($value) => $value !== null), $payload[1]);
    }
}
