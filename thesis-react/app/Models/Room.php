<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;  // ADDED

class Room extends Model
{
    use HasFactory, SoftDeletes;  // ADDED SoftDeletes

    protected $fillable = [
        'room_number',
        'room_type',
        'capacity',
        'price_per_night',
        'price_day_tour',
        'floor',
        'status',
        'description',
        'amenities',
        'images',
        'show_on_website',
    ];

    protected $casts = [
        'amenities' => 'array',
        'images' => 'array',
        'price_per_night' => 'decimal:2',
        'price_day_tour' => 'decimal:2',
        'show_on_website' => 'boolean',
    ];

    // Relationships
    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function bookings()
    {
        return $this->belongsToMany(Booking::class, 'booking_rooms')
            ->withPivot(
                'price_per_night',
                'nights',
                'subtotal',
                'room_status',
                'checked_out_at',
                'checkout_extra_charges',
                'extended_checkout',
                'extension_charge',
                'extension_approved_at',
                'extension_approved_by'
            )
            ->withTimestamps();
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->whereNotIn('status', ['occupied', 'maintenance', 'cleaning']);
    }

    public function scopeOccupied($query)
    {
        return $query->where('status', 'occupied');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('room_type', $type);
    }

    public function scopeByFloor($query, $floor)
    {
        return $query->where('floor', $floor);
    }

    // Helpers
    public function isAvailable()
    {
        return $this->status === 'available';
    }

    public function markAsOccupied()
    {
        return $this->update(['status' => 'occupied']);
    }

    public function markAsAvailable()
    {
        return $this->update(['status' => 'available']);
    }
}
