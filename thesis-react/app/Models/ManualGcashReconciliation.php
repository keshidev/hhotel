<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualGcashReconciliation extends Model
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_EXCEPTION_OPEN = 'exception_open';

    public const STATUS_EXCEPTION_RESOLVED = 'exception_resolved';

    public const RESOLUTION_MATCHED = 'matched';

    public const RESOLUTION_PAYMENT_REVIEW = 'payment_review_required';

    public const RESOLUTION_REFUND_REQUIRED = 'refund_required';

    public const EXCEPTION_TYPES = [
        'missing_transaction',
        'duplicate_reference',
        'amount_mismatch',
        'time_mismatch',
        'reversed_transaction',
        'other',
    ];

    protected $fillable = [
        'submission_id', 'payment_id', 'booking_id', 'status',
        'statement_reference', 'normalized_statement_reference', 'matched_reference_claim',
        'statement_amount', 'statement_paid_at', 'exception_type', 'notes',
        'reconciled_by', 'reconciled_at', 'resolution', 'resolution_notes',
        'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'statement_amount' => 'decimal:2',
        'statement_paid_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ManualGcashSubmission::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
