<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CancellationController extends Controller
{
    /**
     * Get all cancellations with filters.
     */
    public function index(Request $request)
    {
        $query = Cancellation::with(['booking.primaryGuest', 'booking.bookingRooms.room']);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('booking', function ($bookingQuery) use ($search) {
                    $bookingQuery->where('reference_number', 'like', "%{$search}%")
                        ->orWhereHas('primaryGuest', function ($guestQuery) use ($search) {
                            $guestQuery->where('name', 'like', "%{$search}%");
                        });
                });
            });
        }

        if ($request->filled('refund_status') && $request->refund_status !== 'All') {
            $filter = strtolower((string) $request->refund_status);
            $map = [
                'refunded' => 'refunded',
                'pending' => 'pending',
                'no refund' => 'none',
            ];

            if (isset($map[$filter])) {
                $query->where('refund_status', $map[$filter]);
            }
        }

        $cancellations = $query->latest()->get()->map(fn ($cancellation) => $this->transformCancellation($cancellation));

        return response()->json([
            'cancellations' => $cancellations,
            'total' => $cancellations->count(),
        ]);
    }

    /**
     * Get single cancellation details.
     */
    public function show($id)
    {
        $cancellation = Cancellation::with(['booking.primaryGuest', 'booking.bookingRooms.room'])
            ->findOrFail($this->parseCancellationId($id));

        return response()->json($this->transformCancellation($cancellation));
    }

    /**
     * Update refund workflow state for a cancellation.
     */
    public function updateRefundStatus(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Receptionist cannot directly process refunds. Submit a cancellation request and wait for admin approval/finalization.',
        ], 403);
    }

    private function parseCancellationId($id): int
    {
        if (is_numeric($id)) {
            return (int) $id;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $id);
    }

    private function transformCancellation(Cancellation $cancellation): array
    {
        $booking = $cancellation->booking;
        $primaryGuest = $booking?->primaryGuest;
        $roomPivot = $booking?->bookingRooms->first();
        $room = $roomPivot?->room;

        $refundStatusKey = (string) ($cancellation->refund_status ?? 'none');
        $refundStatus = match ($refundStatusKey) {
            'refunded' => 'Refunded',
            'pending' => 'Pending',
            default => 'No Refund',
        };

        return [
            'id' => 'CAN' . str_pad((string) $cancellation->id, 3, '0', STR_PAD_LEFT),
            'bookingId' => $booking?->reference_number ?? 'N/A',
            'guest' => $primaryGuest?->name ?? 'N/A',
            'phone' => $primaryGuest?->phone ?? 'N/A',
            'email' => $primaryGuest?->email ?? 'N/A',
            'room' => $room ? ($room->room_type . ' ' . $room->room_number) : 'N/A',
            'checkIn' => $this->formatDate($booking?->check_in),
            'checkOut' => $this->formatDate($booking?->check_out),
            'cancelledOn' => $cancellation->cancelled_at?->format('Y-m-d')
                ?? $cancellation->created_at?->format('Y-m-d')
                ?? 'N/A',
            'reason' => $cancellation->reason,
            'amount' => '₱' . number_format((float) ($booking?->total_amount ?? 0), 2),
            'refundStatus' => $refundStatus,
            'refundStatusKey' => $refundStatusKey,
            'refundAmount' => (float) $cancellation->refund_amount,
            'nights' => $roomPivot?->nights
                ?? ($booking ? Carbon::parse($booking->check_in)->diffInDays(Carbon::parse($booking->check_out)) : 0),
            'note' => $cancellation->staff_note,
        ];
    }

    private function formatDate($value): string
    {
        if (!$value) {
            return 'N/A';
        }

        return Carbon::parse($value)->format('Y-m-d');
    }
}
