<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Helpers\NotificationHelper;
use App\Models\Booking;
use App\Models\EarlyCheckInRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EarlyCheckInApprovalService
{
    public function __construct(private CancellationApprovalService $cancellationApprovalService)
    {
    }

    public function create(string|int $bookingKey, string $reason, User $requester): EarlyCheckInRequest
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new \RuntimeException('REASON_REQUIRED');
        }

        try {
            return DB::transaction(function () use ($bookingKey, $reason, $requester) {
                $booking = $this->findBookingForUpdate($bookingKey);

                if ($booking->booking_status !== 'confirmed') {
                    throw new \RuntimeException('BOOKING_NOT_CONFIRMED');
                }

                $this->cancellationApprovalService->assertLifecycleUnlockedForLockedBooking(
                    booking: $booking,
                    actionCode: 'early_checkin_request'
                );

                $officialCheckIn = $this->officialCheckInAt($booking);
                $this->assertRequestWindowOpen($booking, $officialCheckIn);
                $this->expireActiveForLockedBooking($booking, $officialCheckIn);

                $active = EarlyCheckInRequest::query()
                    ->where('booking_id', $booking->id)
                    ->where('active_slot', 1)
                    ->lockForUpdate()
                    ->first();

                if ($active?->status === EarlyCheckInRequest::STATUS_PENDING) {
                    throw new \RuntimeException('OPEN_REQUEST_EXISTS');
                }

                if ($active?->status === EarlyCheckInRequest::STATUS_APPROVED) {
                    throw new \RuntimeException('REQUEST_ALREADY_APPROVED');
                }

                $request = EarlyCheckInRequest::create([
                    'booking_id' => $booking->id,
                    'reason' => $reason,
                    'status' => EarlyCheckInRequest::STATUS_PENDING,
                    'requested_by' => $requester->id,
                    'expires_at' => $officialCheckIn,
                    'active_slot' => 1,
                ]);

                AuditHelper::log(
                    actionActivity: 'Early Check-In Approval Requested',
                    modulePage: 'Check-In Module',
                    modelType: 'EarlyCheckInRequest',
                    modelId: $request->id,
                    recordAffected: 'Booking ' . $booking->reference_number,
                    oldValues: null,
                    newValues: [
                        'status' => $request->status,
                        'reason' => $request->reason,
                        'expires_at' => $request->expires_at?->toDateTimeString(),
                    ],
                    action: 'created',
                    actorUser: $requester
                );

                $this->notifyAdmins($request, $booking, $requester);

                return $request->fresh(['booking.primaryGuest', 'requester', 'decider']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new \RuntimeException('OPEN_REQUEST_EXISTS', previous: $exception);
        }
    }

    public function approve(int $requestId, User $admin, ?string $decisionNote = null): EarlyCheckInRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $requestSnapshot = EarlyCheckInRequest::query()->findOrFail($requestId);
            $booking = Booking::query()->lockForUpdate()->findOrFail($requestSnapshot->booking_id);
            $request = EarlyCheckInRequest::query()->lockForUpdate()->findOrFail($requestId);

            $this->assertPending($request);
            if ($booking->booking_status !== 'confirmed') {
                throw new \RuntimeException('BOOKING_NOT_CONFIRMED');
            }

            $this->assertRequestWindowOpen($booking, $request->expires_at ?? $this->officialCheckInAt($booking));

            $request->update([
                'status' => EarlyCheckInRequest::STATUS_APPROVED,
                'decision_note' => $this->nullableTrim($decisionNote),
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);

            $this->auditDecision($request, $booking, $admin, 'Approved');
            $this->notifyRequester($request, $booking, 'approved');

            return $request->fresh(['booking.primaryGuest', 'requester', 'decider']);
        }, 3);
    }

    public function reject(int $requestId, User $admin, string $decisionNote): EarlyCheckInRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $decisionNote) {
            $requestSnapshot = EarlyCheckInRequest::query()->findOrFail($requestId);
            $booking = Booking::query()->lockForUpdate()->findOrFail($requestSnapshot->booking_id);
            $request = EarlyCheckInRequest::query()->lockForUpdate()->findOrFail($requestId);

            $this->assertPending($request);

            $request->update([
                'status' => EarlyCheckInRequest::STATUS_REJECTED,
                'decision_note' => trim($decisionNote),
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'active_slot' => null,
            ]);

            $this->auditDecision($request, $booking, $admin, 'Rejected');
            $this->notifyRequester($request, $booking, 'rejected');

            return $request->fresh(['booking.primaryGuest', 'requester', 'decider']);
        }, 3);
    }

    public function consumeApprovedForLockedBooking(
        Booking $booking,
        User $actor,
        Carbon $officialCheckIn
    ): EarlyCheckInRequest {
        $this->expireActiveForLockedBooking($booking, $officialCheckIn);

        $request = EarlyCheckInRequest::query()
            ->where('booking_id', $booking->id)
            ->where('active_slot', 1)
            ->lockForUpdate()
            ->first();

        if ($request?->status === EarlyCheckInRequest::STATUS_PENDING) {
            throw new \RuntimeException('EARLY_APPROVAL_PENDING');
        }

        if (! $request || $request->status !== EarlyCheckInRequest::STATUS_APPROVED) {
            throw new \RuntimeException('EARLY_APPROVAL_REQUIRED:' . $officialCheckIn->format('g:i A'));
        }

        $request->update([
            'status' => EarlyCheckInRequest::STATUS_CONSUMED,
            'consumed_by' => $actor->id,
            'consumed_at' => now(),
            'active_slot' => null,
        ]);

        return $request;
    }

    public function expireActiveForLockedBooking(Booking $booking, Carbon $officialCheckIn): void
    {
        if (now()->lt($officialCheckIn)) {
            return;
        }

        EarlyCheckInRequest::query()
            ->where('booking_id', $booking->id)
            ->where('active_slot', 1)
            ->whereIn('status', [EarlyCheckInRequest::STATUS_PENDING, EarlyCheckInRequest::STATUS_APPROVED])
            ->lockForUpdate()
            ->update([
                'status' => EarlyCheckInRequest::STATUS_EXPIRED,
                'active_slot' => null,
                'updated_at' => now(),
            ]);
    }

    public function officialCheckInAt(Booking $booking): Carbon
    {
        $checkInAt = Carbon::parse($booking->check_in);
        $isDayUse = $booking->stay_type === 'day_use' || (bool) $booking->is_day_tour;

        if ($isDayUse) {
            return $checkInAt;
        }

        $configuredTime = (string) \App\Models\SystemSetting::read('check_in_time', '15:00');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $configuredTime)) {
            $configuredTime = '15:00';
        }

        [$hour, $minute] = array_map('intval', explode(':', $configuredTime));

        return $checkInAt->copy()->startOfDay()->setTime($hour, $minute);
    }

    private function assertRequestWindowOpen(Booking $booking, Carbon $officialCheckIn): void
    {
        $checkInDate = Carbon::parse($booking->check_in)->startOfDay();
        if (now()->isBefore($checkInDate)) {
            throw new \RuntimeException('CHECKIN_DATE_NOT_YET:' . $checkInDate->format('M d, Y'));
        }

        if (now()->gte($officialCheckIn)) {
            throw new \RuntimeException('EARLY_WINDOW_CLOSED');
        }
    }

    private function assertPending(EarlyCheckInRequest $request): void
    {
        if ($request->status !== EarlyCheckInRequest::STATUS_PENDING || $request->active_slot !== 1) {
            throw new \RuntimeException('INVALID_STATUS');
        }

        if ($request->expires_at?->lte(now())) {
            $request->update([
                'status' => EarlyCheckInRequest::STATUS_EXPIRED,
                'active_slot' => null,
            ]);
            throw new \RuntimeException('REQUEST_EXPIRED');
        }
    }

    private function findBookingForUpdate(string|int $bookingKey): Booking
    {
        return Booking::query()
            ->where(function ($query) use ($bookingKey) {
                $query->where('reference_number', $bookingKey);
                if (is_numeric($bookingKey)) {
                    $query->orWhere('id', (int) $bookingKey);
                }
            })
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function auditDecision(EarlyCheckInRequest $request, Booking $booking, User $admin, string $decision): void
    {
        AuditHelper::log(
            actionActivity: "Early Check-In Request {$decision}",
            modulePage: 'Check-In Module',
            modelType: 'EarlyCheckInRequest',
            modelId: $request->id,
            recordAffected: 'Booking ' . $booking->reference_number,
            oldValues: ['status' => EarlyCheckInRequest::STATUS_PENDING],
            newValues: [
                'status' => $request->status,
                'decision_note' => $request->decision_note,
                'decided_by' => $admin->name,
            ],
            action: 'updated',
            actorUser: $admin
        );
    }

    private function notifyAdmins(EarlyCheckInRequest $request, Booking $booking, User $requester): void
    {
        User::query()->where('role', 'admin')->where('status', 'active')->pluck('id')->each(
            fn ($userId) => NotificationHelper::send(
                (int) $userId,
                'early_checkin_requested',
                'Early Check-In Approval Needed',
                "{$requester->name} requested early check-in for booking {$booking->reference_number}.",
                ['request_id' => $request->id, 'booking_id' => $booking->id]
            )
        );
    }

    private function notifyRequester(EarlyCheckInRequest $request, Booking $booking, string $decision): void
    {
        if (! $request->requested_by) {
            return;
        }

        NotificationHelper::send(
            (int) $request->requested_by,
            $decision === 'approved' ? 'early_checkin_approved' : 'early_checkin_rejected',
            'Early Check-In Request ' . ucfirst($decision),
            "Early check-in for booking {$booking->reference_number} was {$decision}.",
            ['request_id' => $request->id, 'booking_id' => $booking->id]
        );
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
