<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const TYPE_DOWNPAYMENT = 'downpayment';

    public const TYPE_FULL_PAYMENT = 'full_payment';

    public const TYPE_BALANCE_PAYMENT = 'balance_payment';

    public const TYPE_REFUND = 'refund';

    public const PURPOSE_BOOKING = 'booking';

    public const PURPOSE_REBOOKING_ADJUSTMENT = 'rebooking_adjustment';

    public const LIFECYCLE_PENDING = 'pending';

    public const LIFECYCLE_AWAITING_PAYMENT = 'awaiting_payment';

    public const LIFECYCLE_PROOF_SUBMITTED = 'proof_submitted';

    public const LIFECYCLE_PENDING_VERIFICATION = 'pending_verification';

    public const LIFECYCLE_REJECTED = 'rejected';

    public const LIFECYCLE_EXPIRED = 'expired';

    public const LIFECYCLE_AUTHORIZED = 'authorized';

    public const LIFECYCLE_CAPTURE_PENDING = 'capture_pending';

    public const LIFECYCLE_CAPTURE_UNKNOWN = 'capture_unknown';

    public const LIFECYCLE_PAID = 'paid';

    public const LIFECYCLE_PAID_UNDER_REVIEW = 'paid_under_review';

    public const LIFECYCLE_REFUND_REQUIRED = 'refund_required';

    public const LIFECYCLE_ASSIGNMENT_FAILED = 'assignment_failed';

    public const LIFECYCLE_FAILED = 'failed';

    public const LIFECYCLE_REFUNDED = 'refunded';

    protected $fillable = [
        'booking_id',
        'rebooking_id',
        'amount',
        'amount_tendered',
        'change_due',
        'payment_type',
        'purpose',
        'payment_method',
        'payment_status',
        'lifecycle_status',
        'lifecycle_message',
        'lifecycle_updated_at',
        'payment_due_at',
        'proof_submitted_at',
        'review_due_at',
        'review_hold_until',
        'submission_attempts',
        'transaction_reference',
        'proof_image',
        'paid_at',
        'verified_by',
        'verified_at',
        'notes',
        'staff_recording_key',
        // Provider and reconciliation metadata
        'provider',
        'provider_reference',
        'paid_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_due' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'lifecycle_updated_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'proof_submitted_at' => 'datetime',
        'review_due_at' => 'datetime',
        'review_hold_until' => 'datetime',
        'submission_attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Payment $payment) {
            if ($payment->isDirty('payment_status') && ! $payment->isDirty('lifecycle_status')) {
                $payment->lifecycle_status = self::lifecycleForAccountingStatus(
                    (string) $payment->payment_status
                );
            }

            if ($payment->isDirty('lifecycle_status')) {
                $payment->lifecycle_updated_at = now();
            }
        });
    }

    // ─────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function rebooking()
    {
        return $this->belongsTo(Rebooking::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function manualGcashSubmissions()
    {
        return $this->hasMany(ManualGcashSubmission::class);
    }

    // ─────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────

    public function scopeCompleted($query)
    {
        return $query->where('payment_status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('payment_status', 'pending');
    }

    public function scopeDownpayment($query)
    {
        return $query->where('payment_type', 'downpayment');
    }

    public function scopeFullPayment($query)
    {
        return $query->where('payment_type', self::TYPE_FULL_PAYMENT);
    }

    public function scopeBalancePayment($query)
    {
        return $query->where('payment_type', self::TYPE_BALANCE_PAYMENT);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    public function markAsCompleted()
    {
        return $this->update([
            'payment_status' => 'completed',
            'paid_at' => now(),
        ]);
    }

    public function verify($userId)
    {
        return $this->update([
            'verified_by' => $userId,
            'verified_at' => now(),
            'payment_status' => 'completed',
        ]);
    }

    public static function netAmountFrom(iterable $payments): float
    {
        $paid = 0.0;
        $refunded = 0.0;

        foreach ($payments as $payment) {
            $type = (string) $payment->payment_type;
            $status = (string) $payment->payment_status;
            $amount = (float) $payment->amount;

            if ($type === self::TYPE_REFUND && in_array($status, ['completed', 'refunded'], true)) {
                $refunded += $amount;
                continue;
            }

            if ($type !== self::TYPE_REFUND && $status === 'completed') {
                $paid += $amount;
            }
        }

        return round(max(0, $paid - $refunded), 2);
    }

    public static function netAmountForBooking(int $bookingId): float
    {
        return self::netAmountFrom(
            self::query()
                ->where('booking_id', $bookingId)
                ->get(['amount', 'payment_type', 'payment_status'])
        );
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'completed';
    }

    public function resolvedLifecycleStatus(): string
    {
        $status = trim((string) $this->lifecycle_status);

        return $status !== ''
            ? $status
            : self::lifecycleForAccountingStatus((string) $this->payment_status);
    }

    public static function lifecycleForAccountingStatus(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'completed' => self::LIFECYCLE_PAID,
            'failed' => self::LIFECYCLE_FAILED,
            'refunded' => self::LIFECYCLE_REFUNDED,
            default => self::LIFECYCLE_PENDING,
        };
    }

    public static function normalizePaymentMethod(?string $paymentMethod, ?string $provider = null): string
    {
        $methodKey = strtolower(trim((string) $paymentMethod));
        $methodKey = str_replace(['_', '-'], ' ', $methodKey);
        $methodKey = preg_replace('/\s+/', ' ', $methodKey) ?? '';

        if (str_contains($methodKey, 'gcash')) {
            return 'gcash';
        }

        if (
            str_contains($methodKey, 'bank transfer')
            || str_contains($methodKey, 'banktransfer')
            || str_contains($methodKey, 'bank')
        ) {
            return 'bank_transfer';
        }

        if (
            str_contains($methodKey, 'credit card')
            || str_contains($methodKey, 'debit card')
            || str_contains($methodKey, 'card')
        ) {
            return 'card';
        }

        return 'cash';
    }

    public static function paymentMethodLabel(?string $paymentMethod, ?string $provider = null): string
    {
        return match (self::normalizePaymentMethod($paymentMethod, $provider)) {
            'gcash' => 'GCash',
            'bank_transfer' => 'Bank Transfer',
            'card' => 'Card',
            default => 'Cash',
        };
    }
}
