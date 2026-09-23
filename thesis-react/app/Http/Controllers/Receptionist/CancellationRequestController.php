<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\CancellationApprovalRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CancellationRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = CancellationApprovalRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'requester', 'approver', 'finalizer'])
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

        $rows = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'requests' => $rows->getCollection()->map(fn (CancellationApprovalRequest $item) => $this->transform($item))->values(),
            'total' => $rows->total(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
        ]);
    }

    public function show($id)
    {
        $request = CancellationApprovalRequest::query()
            ->with(['booking.primaryGuest', 'booking.bookingRooms.room', 'requester', 'approver', 'finalizer', 'cancellation'])
            ->findOrFail($this->parseId($id));

        return response()->json($this->transform($request, true));
    }

    private function parseId($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function transform(CancellationApprovalRequest $request, bool $full = false): array
    {
        $booking = $request->booking;
        $primaryGuest = $booking?->primaryGuest;
        $room = $booking?->bookingRooms?->first()?->room;
        $requestedBy = $request->requester?->name;

        if (!$requestedBy) {
            $requestedBy = $primaryGuest ? 'Guest (Self-Requested)' : 'Unknown';
        }

        $payload = [
            'id' => 'CAR' . str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
            'requestId' => $request->id,
            'bookingId' => $booking?->reference_number ?? 'N/A',
            'bookingStatus' => $booking?->booking_status ?? 'unknown',
            'guest' => $primaryGuest?->name ?? 'N/A',
            'room' => $room ? ($room->room_type . ' ' . $room->room_number) : 'N/A',
            'checkIn' => $this->formatDate($booking?->check_in),
            'checkOut' => $this->formatDate($booking?->check_out),
            'reason' => $request->reason,
            'refundAmount' => (float) $request->refund_amount,
            'refundMethod' => $request->refund_method,
            'status' => $request->status,
            'statusLabel' => $this->statusLabel($request->status),
            'requestedBy' => $requestedBy,
            'requestedAt' => $request->created_at?->toDateTimeString(),
            'approvedBy' => $request->approver?->name,
            'approvedAt' => $request->approved_at?->toDateTimeString(),
            'rejectedAt' => $request->rejected_at?->toDateTimeString(),
            'finalizedBy' => $request->finalizer?->name,
            'finalizedAt' => $request->finalized_at?->toDateTimeString(),
            'refundProcessedAt' => $request->refund_processed_at?->toDateTimeString(),
            'decisionNote' => $request->decision_note,
            'requestNote' => $request->request_note,
            'canBeApproved' => $request->status === CancellationApprovalRequest::STATUS_PENDING_APPROVAL,
            'canBeFinalized' => in_array($request->status, [
                CancellationApprovalRequest::STATUS_APPROVED,
                CancellationApprovalRequest::STATUS_REFUNDED,
            ], true),
        ];

        if ($full) {
            $payload['cancellationId'] = $request->cancellation_id;
            $payload['cancellationRecordId'] = $request->cancellation
                ? ('CAN' . str_pad((string) $request->cancellation->id, 3, '0', STR_PAD_LEFT))
                : null;
        }

        return $payload;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CancellationApprovalRequest::STATUS_PENDING_APPROVAL => 'Pending Approval',
            CancellationApprovalRequest::STATUS_APPROVED => 'Approved',
            CancellationApprovalRequest::STATUS_REJECTED => 'Rejected',
            CancellationApprovalRequest::STATUS_REFUND_PENDING => 'Refund Pending',
            CancellationApprovalRequest::STATUS_REFUNDED => 'Refunded',
            CancellationApprovalRequest::STATUS_CANCELLED => 'Cancelled',
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
