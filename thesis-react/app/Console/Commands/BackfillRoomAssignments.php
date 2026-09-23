<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\RoomAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillRoomAssignments extends Command
{
    protected $signature = 'rooms:backfill-assignments';
    protected $description = 'Backfill missing room assignments for bookings stuck in pending_assignment status';

    public function __construct(private RoomAssignmentService $roomAssignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $targetBookingIds = Booking::query()
            ->where('room_assignment_status', 'pending_assignment')
            ->whereIn('booking_status', ['confirmed', 'checked_in', 'checked_out'])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($targetBookingIds)) {
            $this->info('No stuck bookings found. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info('Found ' . count($targetBookingIds) . ' booking(s) with pending room assignment to backfill.');

        $assignedCount = 0;
        $failed = [];

        foreach ($targetBookingIds as $bookingId) {
            try {
                $result = DB::transaction(function () use ($bookingId) {
                    $booking = Booking::query()
                        ->with(['bookingRooms.room', 'primaryGuest'])
                        ->whereKey($bookingId)
                        ->lockForUpdate()
                        ->first();

                    if (!$booking) {
                        return [
                            'ok' => false,
                            'reference' => "ID {$bookingId}",
                            'reason' => 'booking_not_found',
                        ];
                    }

                    $hasPendingLines = $booking->bookingRooms->contains(fn ($line) => empty($line->room_id));
                    $hasAssignedLines = $booking->bookingRooms->contains(fn ($line) => !empty($line->room_id));
                    $allowHistoricalFallback = in_array((string) $booking->booking_status, ['checked_in', 'checked_out'], true);

                    if (!$hasPendingLines && $hasAssignedLines) {
                        $this->roomAssignmentService->syncAssignmentStatus($booking);
                        $booking->refresh();

                        return [
                            'ok' => (string) $booking->room_assignment_status === 'assigned',
                            'reference' => (string) $booking->reference_number,
                            'booking_status' => (string) $booking->booking_status,
                            'assigned' => [],
                            'unassigned' => [],
                            'reason' => (string) $booking->room_assignment_status === 'assigned'
                                ? 'status_synced_from_existing_rooms'
                                : 'status_sync_failed',
                        ];
                    }

                    $assignmentResult = $this->roomAssignmentService->assignRoomToBooking(
                        booking: $booking,
                        actorLabel: 'System (Backfill Room Assignments)',
                        allowHistoricalFallback: $allowHistoricalFallback
                    );

                    $booking->refresh();
                    $isAssigned = (string) $booking->room_assignment_status === 'assigned';

                    return [
                        'ok' => $isAssigned,
                        'reference' => (string) $booking->reference_number,
                        'booking_status' => (string) $booking->booking_status,
                        'assigned' => $assignmentResult['assigned'] ?? [],
                        'unassigned' => $assignmentResult['unassigned'] ?? [],
                        'reason' => $isAssigned ? 'assigned' : 'pending_lines_remaining',
                    ];
                });
            } catch (\Throwable $e) {
                $result = [
                    'ok' => false,
                    'reference' => "ID {$bookingId}",
                    'reason' => 'exception: ' . $e->getMessage(),
                ];
            }

            if (!empty($result['ok'])) {
                $assignedCount++;
                $this->line('Assigned: ' . ($result['reference'] ?? ('ID ' . $bookingId)));
            } else {
                $failed[] = [
                    'reference' => $result['reference'] ?? ('ID ' . $bookingId),
                    'reason' => $result['reason'] ?? 'unknown',
                    'unassigned' => $result['unassigned'] ?? [],
                ];
                $this->warn('Needs manual attention: ' . ($result['reference'] ?? ('ID ' . $bookingId)));
            }
        }

        $failedCount = count($failed);
        $this->newLine();
        $this->info('Backfill summary');
        $this->line('- Total processed: ' . count($targetBookingIds));
        $this->line('- Successfully assigned/synced: ' . $assignedCount);
        $this->line('- Needs manual attention: ' . $failedCount);

        if ($failedCount > 0) {
            $this->newLine();
            $this->warn('Manual attention list:');
            foreach ($failed as $item) {
                $details = '';
                if (!empty($item['unassigned'])) {
                    $details = ' | unassigned=' . json_encode($item['unassigned']);
                }
                $this->line('- ' . $item['reference'] . ' | reason=' . $item['reason'] . $details);
            }
        }

        return self::SUCCESS;
    }
}

