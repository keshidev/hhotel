<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use DateTimeInterface;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'start_date',
        'end_date',
        'booking_start_date',
        'booking_end_date',
        'min_nights',
        'max_nights',
        'usage_limit',
        'usage_per_user_limit',
        'total_used',
        'online_only',
        'walk_in_only',
        'is_active',
    ];

    protected $casts = [
        'discount_value'       => 'decimal:2',
        'max_discount_amount'  => 'decimal:2',
        // date:Y-m-d ensures the cast format is Y-m-d,
        // paired with serializeDate() below to prevent UTC offset shift.
        'start_date'           => 'date:Y-m-d',
        'end_date'             => 'date:Y-m-d',
        'booking_start_date'   => 'date:Y-m-d',
        'booking_end_date'     => 'date:Y-m-d',
        'min_nights'           => 'integer',
        'max_nights'           => 'integer',
        'usage_limit'          => 'integer',
        'usage_per_user_limit' => 'integer',
        'total_used'           => 'integer',
        'online_only'          => 'boolean',
        'walk_in_only'         => 'boolean',
        'is_active'            => 'boolean',
    ];

    /**
     * Serialize dates for JSON output.
     *
     * - Date-only fields (start_date, end_date, etc.) → Y-m-d
     *   Prevents the UTC offset shift: Asia/Manila midnight becomes
     *   the previous day at 16:00 UTC when serialized as ISO-8601.
     *
     * - Timestamp fields (created_at, updated_at) → full ISO-8601
     *   These need the time component so they display correctly in the UI.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        // If the time is exactly midnight (00:00:00), it came from a DATE
        // column — serialize as date-only to avoid the UTC rollback.
        // Timestamps from TIMESTAMP columns will always have a non-zero
        // time in Manila timezone, so they fall through to ISO-8601.
        if ($date->format('H:i:s') === '00:00:00') {
            return $date->format('Y-m-d');
        }

        return $date->format('Y-m-d\TH:i:s.u\Z');
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function usages()
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** How many times a specific email has used this code. */
    public function usageCountForEmail(string $email): int
    {
        return $this->usages()
            ->active()
            ->where('guest_email', strtolower(trim($email)))
            ->count();
    }

    /** Whether the code is within its active date window right now. */
    public function isCurrentlyActive(): bool
    {
        if (!$this->is_active) return false;
        $today = now()->toDateString();
        return $today >= $this->start_date->toDateString()
            && $today <= $this->end_date->toDateString();
    }

    /** Compute the discount amount for a given subtotal. */
    public function computeDiscount(float $subtotal): float
    {
        if ($this->discount_type === 'percentage') {
            $discount = $subtotal * ($this->discount_value / 100);
            if ($this->max_discount_amount !== null) {
                $discount = min($discount, (float) $this->max_discount_amount);
            }
        } else {
            $discount = (float) $this->discount_value;
        }

        // Discount can never exceed the subtotal
        return round(min($discount, $subtotal), 2);
    }
}
