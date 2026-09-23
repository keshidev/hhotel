<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EarlyCheckInRequest;
use App\Services\EarlyCheckInApprovalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class EarlyCheckInApprovalController extends Controller
{
    public function __construct(private EarlyCheckInApprovalService $approvalService)
    {
    }

    public function index(Request $request)
    {
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));

        $query = EarlyCheckInRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'requester', 'decider', 'consumer'])
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('id', preg_replace('/\D/', '', $search) ?: 0)
                    ->orWhereHas('booking', fn ($booking) => $booking->where('reference_number', 'like', "%{$search}%"))
                    ->orWhereHas('booking.primaryGuest', fn ($guest) => $guest->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'success' => true,
            'requests' => $query->limit(200)->get()->map(fn ($row) => $this->transform($row))->values(),
        ]);
    }

    public function show($id)
    {
        $row = EarlyCheckInRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'requester', 'decider', 'consumer'])
            ->findOrFail($this->parseId($id));

        return response()->json(['success' => true, 'request' => $this->transform($row)]);
    }

    public function approve(Request $request, $id)
    {
        $validated = $request->validate(['decision_note' => ['nullable', 'string', 'max:500']]);

        try {
            $row = $this->approvalService->approve(
                $this->parseId($id),
                $request->user(),
                $validated['decision_note'] ?? null
            );
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Early check-in request not found.'], 404);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Early check-in approved. The receptionist may now complete check-in.',
            'request' => $this->transform($row),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate(['decision_note' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $row = $this->approvalService->reject(
                $this->parseId($id),
                $request->user(),
                $validated['decision_note']
            );
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Early check-in request not found.'], 404);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Early check-in request rejected.',
            'request' => $this->transform($row),
        ]);
    }

    private function errorResponse(\RuntimeException $exception)
    {
        $message = match ($exception->getMessage()) {
            'INVALID_STATUS' => 'This request has already been decided or used.',
            'REQUEST_EXPIRED', 'EARLY_WINDOW_CLOSED' => 'This request expired because official check-in time has arrived.',
            'BOOKING_NOT_CONFIRMED' => 'The booking is no longer confirmed and cannot be approved.',
            default => 'Unable to process the early check-in request.',
        };

        return response()->json(['success' => false, 'message' => $message], 409);
    }

    private function parseId($value): int
    {
        return is_numeric($value) ? (int) $value : (int) preg_replace('/\D/', '', (string) $value);
    }

    private function transform(EarlyCheckInRequest $request): array
    {
        $booking = $request->booking;
        $rooms = $booking?->bookingRooms
            ?->map(fn ($line) => $line->room ? trim($line->room->room_type . ' ' . $line->room->room_number) : null)
            ->filter()
            ->values()
            ->all() ?? [];

        return [
            'id' => 'ECI' . str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
            'requestId' => $request->id,
            'bookingId' => $booking?->reference_number ?? 'N/A',
            'guest' => $booking?->primaryGuest?->name ?? 'N/A',
            'rooms' => $rooms,
            'checkIn' => $this->formatDate($booking?->check_in),
            'officialCheckIn' => $booking ? $this->approvalService->officialCheckInAt($booking)->format('M d, Y g:i A') : 'N/A',
            'reason' => $request->reason,
            'status' => $request->status,
            'statusLabel' => ucfirst(str_replace('_', ' ', $request->status)),
            'requestedBy' => $request->requester?->name ?? 'Unknown',
            'requestedAt' => $request->created_at?->toDateTimeString(),
            'expiresAt' => $request->expires_at?->toDateTimeString(),
            'decisionNote' => $request->decision_note,
            'decidedBy' => $request->decider?->name,
            'decidedAt' => $request->decided_at?->toDateTimeString(),
            'consumedBy' => $request->consumer?->name,
            'consumedAt' => $request->consumed_at?->toDateTimeString(),
            'canApprove' => $request->status === EarlyCheckInRequest::STATUS_PENDING && $request->expires_at?->isFuture(),
            'canReject' => $request->status === EarlyCheckInRequest::STATUS_PENDING,
        ];
    }

    private function formatDate($value): string
    {
        return $value ? Carbon::parse($value)->format('M d, Y g:i A') : 'N/A';
    }
}
