<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\RoomTransferRequest;
use App\Services\RoomTransferRequestService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransferRequestController extends Controller
{
    public function __construct(
        private RoomTransferRequestService $roomTransferRequestService
    ) {
    }

    public function index(Request $request)
    {
        $query = RoomTransferRequest::query()
            ->with([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('booking', function ($bookingQuery) use ($search) {
                    $bookingQuery->where('reference_number', 'like', "%{$search}%")
                        ->orWhereHas('primaryGuest', function ($guestQuery) use ($search) {
                            $guestQuery->where('name', 'like', "%{$search}%");
                        });
                })->orWhere('id', (int) preg_replace('/[^0-9]/', '', $search));
            });
        }

        if ($request->filled('status')) {
            $status = strtolower(trim((string) $request->status));
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $rows = $query->get()->map(fn (RoomTransferRequest $item) => $this->transform($item));

        return response()->json([
            'requests' => $rows,
            'total' => $rows->count(),
        ]);
    }

    public function show($id)
    {
        $row = RoomTransferRequest::query()
            ->with([
                'booking.primaryGuest',
                'booking.bookingRooms.room',
                'bookingRoom.room',
                'currentRoom',
                'targetRoom',
                'requester',
                'approver',
                'completer',
            ])
            ->findOrFail($this->parseId($id));

        return response()->json($this->transform($row, true));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required',
            'booking_room_id' => 'nullable|integer|exists:booking_rooms,id',
            'target_room_id' => 'required|integer|exists:rooms,id',
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $row = $this->roomTransferRequestService->createRequest(
                bookingKey: (string) $validated['booking_id'],
                payload: $validated,
                requester: $request->user()
            );
        } catch (\RuntimeException $e) {
            return $this->handleException($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Room transfer request submitted for admin approval.',
            'request' => $this->transform($row, true),
        ], 201);
    }

    private function handleException(\RuntimeException $e)
    {
        $message = match ($e->getMessage()) {
            'BOOKING_NOT_TRANSFERABLE' => 'Room transfer is only allowed for checked-in bookings.',
            'BOOKING_ROOM_REQUIRED' => 'Please select which room line to transfer for this booking.',
            'BOOKING_ROOM_MISSING' => 'Room assignment is missing for this booking.',
            'OPEN_REQUEST_EXISTS' => 'An open room transfer request already exists for this booking.',
            'TARGET_SAME_AS_CURRENT' => 'Target room cannot be the same as current room.',
            'TARGET_ALREADY_ASSIGNED_TO_BOOKING' => 'Target room is already assigned to another room line in this booking.',
            'TARGET_NOT_AVAILABLE_STATUS' => 'Target room is not currently available for transfer.',
            'TARGET_CAPACITY_MISMATCH' => 'Target room cannot accommodate the guest count.',
            'TARGET_OVERLAP' => 'Target room has an overlapping active booking.',
            default => 'Unable to submit room transfer request.',
        };

        $statusCode = match ($e->getMessage()) {
            'OPEN_REQUEST_EXISTS' => 409,
            'TARGET_OVERLAP', 'TARGET_ALREADY_ASSIGNED_TO_BOOKING' => 409,
            'TARGET_NOT_AVAILABLE_STATUS' => 409,
            default => 422,
        };

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $statusCode);
    }

    private function parseId($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function transform(RoomTransferRequest $request, bool $full = false): array
    {
        $booking = $request->booking;
        $primaryGuest = $booking?->primaryGuest;
        $currentRoom = $request->currentRoom ?? $request->bookingRoom?->room;
        $targetRoom = $request->targetRoom;

        $payload = [
            'id' => 'RTR' . str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
            'requestId' => $request->id,
            'bookingId' => $booking?->reference_number ?? 'N/A',
            'bookingStatus' => $booking?->booking_status ?? 'unknown',
            'guest' => $primaryGuest?->name ?? 'N/A',
            'checkIn' => $this->formatDate($booking?->check_in),
            'checkOut' => $this->formatDate($booking?->check_out),
            'currentRoom' => $currentRoom ? ($currentRoom->room_type . ' ' . $currentRoom->room_number) : 'N/A',
            'targetRoom' => $targetRoom ? ($targetRoom->room_type . ' ' . $targetRoom->room_number) : 'N/A',
            'currentRoomId' => $currentRoom?->id,
            'targetRoomId' => $targetRoom?->id,
            'reason' => $request->reason,
            'status' => $request->status,
            'statusLabel' => $this->statusLabel($request->status),
            'requestedBy' => $request->requester?->name ?? 'Unknown',
            'requestedAt' => $request->created_at?->toDateTimeString(),
            'approvedBy' => $request->approver?->name,
            'approvedAt' => $request->approved_at?->toDateTimeString(),
            'rejectedAt' => $request->rejected_at?->toDateTimeString(),
            'completedBy' => $request->completer?->name,
            'completedAt' => $request->completed_at?->toDateTimeString(),
            'decisionNote' => $request->decision_note,
            'completionNote' => $request->completion_note,
            'canBeApproved' => $request->status === RoomTransferRequest::STATUS_PENDING_APPROVAL,
            'canBeCompleted' => $request->status === RoomTransferRequest::STATUS_APPROVED,
        ];

        if ($full) {
            $payload['bookingRoomId'] = $request->booking_room_id;
        }

        return $payload;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            RoomTransferRequest::STATUS_PENDING_APPROVAL => 'Pending Approval',
            RoomTransferRequest::STATUS_APPROVED => 'Approved',
            RoomTransferRequest::STATUS_REJECTED => 'Rejected',
            RoomTransferRequest::STATUS_COMPLETED => 'Completed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return 'N/A';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }
}
