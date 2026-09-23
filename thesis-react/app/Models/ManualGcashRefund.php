<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualGcashRefund extends Model
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'cancellation_approval_request_id',
        'booking_id',
        'source_payment_id',
        'refund_payment_id',
        'status',
        'approved_amount',
        'approved_by',
        'approved_at',
        'recipient_name',
        'recipient_account',
        'recipient_account_last_four',
        'gcash_reference',
        'normalized_gcash_reference',
        'processed_at',
        'processed_by',
        'reason',
        'proof_disk',
        'proof_path',
        'proof_original_name',
        'proof_mime_type',
        'proof_size',
        'proof_sha256',
        'proof_retention_expires_at',
        'proof_disposal_started_at',
        'proof_deleted_at',
        'proof_deletion_reason',
    ];

    protected $hidden = ['recipient_account', 'proof_path', 'proof_sha256'];

    protected $casts = [
        'approved_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'recipient_account' => 'encrypted',
        'processed_at' => 'datetime',
        'proof_retention_expires_at' => 'datetime',
        'proof_disposal_started_at' => 'datetime',
        'proof_deleted_at' => 'datetime',
    ];

    public function approvalRequest()
    {
        return $this->belongsTo(CancellationApprovalRequest::class, 'cancellation_approval_request_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function sourcePayment()
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function refundPayment()
    {
        return $this->belongsTo(Payment::class, 'refund_payment_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
