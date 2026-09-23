<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CancellationApprovalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REFUND_PENDING = 'refund_pending';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'booking_id',
        'reason',
        'refund_amount',
        'refund_method',
        'request_note',
        'requested_by',
        'status',
        'decision_note',
        'approved_by',
        'approved_at',
        'rejected_at',
        'refund_processed_at',
        'finalized_by',
        'finalized_at',
        'cancellation_id',
        'refund_recipient_name',
        'refund_recipient_account',
        'refund_recipient_confirmed_at',
    ];

    protected $casts = [
        'refund_recipient_name' => 'encrypted',
        'refund_recipient_account' => 'encrypted',
        'refund_recipient_confirmed_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'refund_processed_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    protected $hidden = ['refund_recipient_name', 'refund_recipient_account'];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function cancellation()
    {
        return $this->belongsTo(Cancellation::class);
    }

    public function manualGcashRefund()
    {
        return $this->hasOne(ManualGcashRefund::class);
    }
}
