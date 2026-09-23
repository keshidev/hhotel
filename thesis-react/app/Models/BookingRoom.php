<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_id',
        'requested_room_type',
        'price_per_night',
        'nights',
        'subtotal',
        'checked_out_at',
        'room_status',
        'checkout_extra_charges',
        'extended_checkout',
        'extension_charge',
        'extension_approved_at',
        'extension_approved_by',
    ];

    protected $casts = [
        'price_per_night' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'checked_out_at' => 'datetime',
        'checkout_extra_charges' => 'decimal:2',
        'extended_checkout' => 'datetime',
        'extension_charge' => 'decimal:2',
        'extension_approved_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('room_status')
                ->orWhere('room_status', 'active');
        });
    }

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function extensionApprover()
    {
        return $this->belongsTo(User::class, 'extension_approved_by');
    }
}
