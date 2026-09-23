<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'label',
        'category',
        'amount',
        'created_by',
        'operation_token',
        'operation_line',
        'operation_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'operation_line' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
