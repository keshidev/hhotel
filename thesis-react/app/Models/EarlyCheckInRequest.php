<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EarlyCheckInRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'booking_id',
        'reason',
        'status',
        'requested_by',
        'decision_note',
        'decided_by',
        'decided_at',
        'consumed_by',
        'consumed_at',
        'expires_at',
        'active_slot',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
        'active_slot' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function consumer()
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }
}
