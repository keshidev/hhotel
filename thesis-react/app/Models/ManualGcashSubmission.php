<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ManualGcashSubmission extends Model
{
    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ESCALATED = 'escalated';

    public const SOURCE_CUSTOMER = 'customer_proof';

    public const SOURCE_FRONT_DESK = 'front_desk';

    protected $fillable = [
        'payment_id', 'booking_id', 'attempt_number', 'submission_source', 'transaction_reference',
        'normalized_transaction_reference', 'active_reference_claim', 'sender_name',
        'submitted_amount', 'paid_at', 'status', 'declaration_accepted_at',
        'proof_disk', 'proof_path', 'proof_original_name', 'proof_mime_type',
        'proof_size', 'proof_sha256', 'proof_retention_expires_at',
        'proof_disposal_started_at', 'proof_deleted_at', 'proof_deletion_reason',
        'submitted_at', 'review_due_at',
        'escalation_due_at', 'reviewed_by', 'reviewed_at', 'review_reason',
        'admin_override',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'submitted_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'declaration_accepted_at' => 'datetime',
        'proof_size' => 'integer',
        'proof_retention_expires_at' => 'datetime',
        'proof_disposal_started_at' => 'datetime',
        'proof_deleted_at' => 'datetime',
        'submitted_at' => 'datetime',
        'review_due_at' => 'datetime',
        'escalation_due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'admin_override' => 'boolean',
    ];

    protected $hidden = ['proof_path', 'proof_sha256'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reconciliation(): HasOne
    {
        return $this->hasOne(ManualGcashReconciliation::class, 'submission_id');
    }
}
