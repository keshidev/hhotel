<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCodeUsage extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'promo_code_id',
        'booking_id',
        'guest_email',
        'discount_amount',
        'status',
        'reserved_at',
        'consumed_at',
        'released_at',
        'release_reason',
        'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'reserved_at'     => 'datetime',
        'consumed_at'     => 'datetime',
        'released_at'     => 'datetime',
        'used_at'         => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_RESERVED, self::STATUS_CONSUMED]);
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
