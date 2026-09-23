<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BookingRoom;

class Rebooking extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    public const FINANCIAL_UNASSESSED = 'unassessed';
    public const FINANCIAL_READY = 'ready';
    public const FINANCIAL_ADDITIONAL_PAYMENT_REQUIRED = 'additional_payment_required';
    public const FINANCIAL_AWAITING_PAYMENT = 'awaiting_payment';
    public const FINANCIAL_PAYMENT_UNDER_REVIEW = 'payment_under_review';
    public const FINANCIAL_REFUND_REVIEW = 'refund_review';
    public const FINANCIAL_REFUND_COMPLETED = 'refund_completed';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_AWAITING_PAYMENT,
    ];

    protected $fillable = [
        'rebooking_group_id',
        'is_group_leader',
        'original_booking_id',
        'original_booking_room_id',
        'original_room_id',
        'new_booking_id',
        'requested_room_id',
        'reason',
        'requested_by',
        'approved_by',
        'status',
        'financial_status',
        'original_total',
        'projected_total',
        'price_difference',
        'adjustment_amount',
        'adjustment_payment_id',
        'quote_expires_at',
        'refund_processed_at',
        'decision_note',
        'approved_at',
        'finalized_at',
    ];

    protected $casts = [
        'rebooking_group_id' => 'integer',
        'is_group_leader' => 'boolean',
        'approved_at' => 'datetime',
        'finalized_at' => 'datetime',
        'original_total' => 'decimal:2',
        'projected_total' => 'decimal:2',
        'price_difference' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'quote_expires_at' => 'datetime',
        'refund_processed_at' => 'datetime',
    ];

    // Relationships
    public function originalBooking()
    {
        return $this->belongsTo(Booking::class, 'original_booking_id');
    }

    public function newBooking()
    {
        return $this->belongsTo(Booking::class, 'new_booking_id');
    }

    public function originalBookingRoom()
    {
        return $this->belongsTo(BookingRoom::class, 'original_booking_room_id');
    }

    public function originalRoom()
    {
        return $this->belongsTo(Room::class, 'original_room_id');
    }

    public function requestedRoom()
    {
        return $this->belongsTo(Room::class, 'requested_room_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function adjustmentPayment()
    {
        return $this->belongsTo(Payment::class, 'adjustment_payment_id');
    }

    public function roomHold()
    {
        return $this->hasOne(RebookingRoomHold::class);
    }

    public function refund()
    {
        return $this->hasOne(RebookingRefund::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->whereNull('finalized_at');
    }

}
