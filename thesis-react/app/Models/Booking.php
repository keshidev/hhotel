<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Room;
use App\Models\CancellationApprovalRequest;
use App\Models\RoomTransferRequest;
use App\Models\EarlyCheckInRequest;
use DateTimeInterface;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'check_in',
        'check_out',
        'number_of_guests',
        'children_ages',
        'booking_status',
        'no_show_marked_at',
        'no_show_marked_by',
        'no_show_contacted',
        'no_show_contact_method',
        'no_show_contacted_at',
        'no_show_contact_outcome',
        'no_show_contact_notes',
        'no_show_cutoff_time',
        'no_show_financial_disposition',
        'no_show_financial_amount',
        'no_show_email_status',
        'no_show_email_attempts',
        'no_show_email_queued_at',
        'no_show_email_sent_at',
        'no_show_email_failed_at',
        'no_show_email_last_error',
        'reservation_status',
        'room_assignment_status',
        'room_assigned_at',
        'checked_in_at',
        'checked_in_by',
        'early_check_in_reason',
        'checked_out_at',
        'checked_out_by',
        'cancelled_reason',
        'expires_at',
        'confirmation_email_status',
        'confirmation_email_attempts',
        'confirmation_email_queued_at',
        'confirmation_email_sent_at',
        'confirmation_email_failed_at',
        'confirmation_email_last_error',
        'staff_booking_notified_at',
        'payment_bootstrap_token_hash',
        'payment_bootstrap_expires_at',
        'special_requests',
        'total_amount',
        'created_by',
        'booking_source',
        'is_day_tour',
        'stay_type',
        'duration_hours',
        'day_tour_start_time',
        'day_tour_end_time',
        'promo_code_id',
        'discount_amount',
        'addons_amount',
        'tax_amount',
        'tax_rate',
        'downpayment_percentage',
        'addons_breakdown',
        'has_been_rebooked',
    ];

    protected $casts = [
        // Keep as datetime so Carbon operations work in PHP code.
        // toArray() override below ensures JSON output uses local time string.
        'check_in'                      => 'datetime',
        'check_out'                     => 'datetime',
        'children_ages'                 => 'array',
        'no_show_marked_at'             => 'datetime',
        'no_show_marked_by'             => 'integer',
        'no_show_contacted'             => 'boolean',
        'no_show_contacted_at'          => 'datetime',
        'no_show_financial_amount'      => 'decimal:2',
        'no_show_email_attempts'        => 'integer',
        'no_show_email_queued_at'       => 'datetime',
        'no_show_email_sent_at'         => 'datetime',
        'no_show_email_failed_at'       => 'datetime',
        'cancelled_reason'              => 'string',
        'room_assignment_status'        => 'string',
        'room_assigned_at'              => 'datetime',
        'checked_in_at'                 => 'datetime',
        'checked_in_by'                 => 'integer',
        'checked_out_at'                => 'datetime',
        'checked_out_by'                => 'integer',
        'expires_at'                    => 'datetime',
        'confirmation_email_attempts'   => 'integer',
        'confirmation_email_queued_at'  => 'datetime',
        'confirmation_email_sent_at'    => 'datetime',
        'confirmation_email_failed_at'  => 'datetime',
        'staff_booking_notified_at'      => 'datetime',
        'payment_bootstrap_expires_at'  => 'datetime',
        'total_amount'                  => 'decimal:2',
        'is_day_tour'                   => 'boolean',
        'stay_type'                     => 'string',
        'duration_hours'                => 'decimal:2',
        'booking_source'                => 'string',
        'discount_amount'               => 'decimal:2',
        'addons_amount'                 => 'decimal:2',
        'tax_amount'                    => 'decimal:2',
        'tax_rate'                      => 'decimal:5',
        'downpayment_percentage'        => 'decimal:2',
        'addons_breakdown'              => 'array',
        'has_been_rebooked'             => 'boolean',
    ];

    /**
     * Override toArray() so that check_in and check_out are always serialized
     * as the raw database string (Y-m-d H:i:s in Manila local time) instead of
     * being converted to UTC ISO-8601 by Laravel's datetime cast serializer.
     *
     * All other datetime fields (expires_at, created_at, etc.) keep their
     * normal UTC ISO serialization since they use TIMESTAMP columns which
     * MySQL manages in UTC.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Replace UTC-shifted values with the raw local datetime string
        foreach (['check_in', 'check_out'] as $field) {
            $raw = $this->getRawOriginal($field);
            if ($raw !== null) {
                $array[$field] = $raw; // "2026-03-21 07:35:00" — no timezone shift
            }
        }

        return $array;
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            if (empty($booking->reference_number)) {
                $booking->reference_number = self::generateReferenceNumber();
            }
        });

        static::updating(function (Booking $booking) {
            $bookingStatusIsCancelled     = $booking->isDirty('booking_status')     && $booking->booking_status     === 'cancelled';
            $reservationStatusIsCancelled = $booking->isDirty('reservation_status') && $booking->reservation_status === 'cancelled';

            if ($bookingStatusIsCancelled && $booking->reservation_status !== 'cancelled') {
                $booking->reservation_status = 'cancelled';
            }
            if ($reservationStatusIsCancelled && $booking->booking_status !== 'cancelled') {
                $booking->booking_status = 'cancelled';
            }
            if ($booking->isDirty('booking_status') && !$booking->isDirty('reservation_status')) {
                $map = [
                    'confirmed'   => 'confirmed',
                    'checked_in'  => 'checked_in',
                    'checked_out' => 'completed',
                    'cancelled'   => 'cancelled',
                    'no_show'     => 'no_show',
                ];
                if (array_key_exists($booking->booking_status, $map)) {
                    $booking->reservation_status = $map[$booking->booking_status];
                }
            }
        });

        static::updated(function (Booking $booking) {
            if (!$booking->wasChanged(['booking_status', 'reservation_status'])) return;

            app(\App\Services\PromoCodeService::class)->synchronizeForBooking($booking);

            if (
                $booking->wasChanged('booking_status')
                && \App\Services\PaymentAccessSessionService::isTerminalBookingStatus((string) $booking->booking_status)
            ) {
                app(\App\Services\PaymentAccessSessionService::class)->revoke(
                    (int) $booking->id,
                    'booking_' . $booking->booking_status
                );
            }

            $roomIds = $booking->bookingRooms()
                ->pluck('room_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();
            if (empty($roomIds)) return;

            if ($booking->wasChanged('booking_status') && $booking->booking_status === 'checked_out') {
                Room::whereIn('id', $roomIds)
                    ->where('status', '!=', 'maintenance')
                    ->update(['status' => 'cleaning']);
            }

            app(\App\Services\RoomStateService::class)->recalculateMany($roomIds);
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function creator()       { return $this->belongsTo(User::class, 'created_by'); }
    public function checkedInBy()   { return $this->belongsTo(User::class, 'checked_in_by'); }
    public function checkedOutBy()  { return $this->belongsTo(User::class, 'checked_out_by'); }
    public function bookingRooms()  { return $this->hasMany(BookingRoom::class); }
    public function rooms()         { return $this->belongsToMany(Room::class, 'booking_rooms')->withPivot('price_per_night', 'nights', 'subtotal', 'room_status', 'checked_out_at', 'checkout_extra_charges', 'extended_checkout', 'extension_charge', 'extension_approved_at', 'extension_approved_by')->withTimestamps(); }
    public function guest()         { return $this->hasOne(BookingGuest::class); }
    public function guests()        { return $this->hasMany(BookingGuest::class); }
    public function payments()      { return $this->hasMany(Payment::class); }
    public function cancellation()  { return $this->hasOne(Cancellation::class); }
    public function cancellationApprovalRequests() { return $this->hasMany(CancellationApprovalRequest::class); }
    public function roomTransferRequests() { return $this->hasMany(RoomTransferRequest::class); }
    public function earlyCheckInRequests() { return $this->hasMany(EarlyCheckInRequest::class); }
    public function promoCode()     { return $this->belongsTo(\App\Models\PromoCode::class); }
    public function primaryGuest()  { return $this->hasOne(BookingGuest::class)->where('is_primary', true); }
    public function charges()       { return $this->hasMany(BookingCharge::class); }

    // Legacy single-record relation (kept for backward compatibility with existing callers).
    public function rebookingAsOriginal() { return $this->hasOne(Rebooking::class, 'original_booking_id'); }
    // New relation for line-item based rebooking requests.
    public function rebookingsAsOriginal() { return $this->hasMany(Rebooking::class, 'original_booking_id'); }
    public function rebookingAsNew()      { return $this->hasOne(Rebooking::class, 'new_booking_id'); }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($q)   { return $q->where('booking_status', 'pending'); }
    public function scopeConfirmed($q) { return $q->where('booking_status', 'confirmed'); }
    public function scopeCheckedIn($q) { return $q->where('booking_status', 'checked_in'); }
    public function scopeCancelled($q) { return $q->where('booking_status', 'cancelled'); }
    public function scopeNoShow($q)    { return $q->where('booking_status', 'no_show'); }

    /**
     * Keep temporary, unpaid online room holds out of staff booking screens.
     * Walk-in bookings remain visible, while online bookings become visible
     * after a non-refund payment has completed.
     */
    public function scopeVisibleToStaff($query)
    {
        return $query->where(function ($visibilityQuery) {
            $visibilityQuery
                ->whereNull('booking_source')
                ->orWhere('booking_source', '!=', 'online')
                ->orWhereHas('payments', function ($paymentQuery) {
                    $paymentQuery
                        ->where('payment_status', 'completed')
                        ->where('payment_type', '!=', Payment::TYPE_REFUND);
                });
        });
    }

    public function scopeUpcoming($query)
    {
        return $query->where('check_in', '>=', now()->toDateString())
            ->whereIn('booking_status', ['pending', 'confirmed']);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('check_in', now()->toDateString())
            ->orWhereDate('check_out', now()->toDateString());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateReferenceNumber(): string
    {
        do {
            $reference = 'BK' . Str::upper(Str::random(16));
        } while (self::where('reference_number', $reference)->exists());
        return $reference;
    }

    public function getTotalPaidAttribute(): float
    {
        if (! $this->exists) {
            return 0.0;
        }

        if ($this->relationLoaded('payments')) {
            return Payment::netAmountFrom($this->payments);
        }

        return Payment::netAmountForBooking((int) $this->id);
    }

    public function getRemainingBalanceAttribute(): float
    {
        return round(max(0, (float) $this->total_amount - (float) $this->total_paid), 2);
    }

    /**
     * getNightsAttribute — works correctly for both online and walk-in bookings.
     * For day use (stay_type = 'day_use' or is_day_tour = true): always returns 1
     * so pricing stays correct (1 unit × day_use_price).
     * For overnight: returns actual calendar days.
     */
    public function getNightsAttribute(): int
    {
        $isDayUse = $this->stay_type === 'day_use' || (bool) $this->is_day_tour;
        if ($isDayUse) return 1;
        return max(1, (int) $this->check_in->diffInDays($this->check_out));
    }

    public function isExpiredPending(): bool
    {
        if ($this->booking_status !== 'pending') return false;

        $hasCompletedPayment = $this->relationLoaded('payments')
            ? $this->payments->contains(fn (Payment $payment) => $payment->payment_status === 'completed')
            : ($this->exists && $this->payments()->where('payment_status', 'completed')->exists());

        if ($hasCompletedPayment) return false;
        if ($this->expires_at) return now()->greaterThan($this->expires_at);
        return $this->created_at < now()->subMinutes((int) config('bookings.pending_expiry_minutes', 30));
    }
}
