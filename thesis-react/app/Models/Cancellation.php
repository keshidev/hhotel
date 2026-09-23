<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cancellation extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'reason',
        'cancelled_by',
        'cancellation_fee',
        'refund_amount',
        'refund_status',
        'refunded_at',
        'staff_note',
        'cancelled_at',
    ];

    protected $casts = [
        'cancellation_fee' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'refund_status' => 'string',
        'refunded_at' => 'datetime',
        'staff_note' => 'string',
        'cancelled_at' => 'datetime',
    ];

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
