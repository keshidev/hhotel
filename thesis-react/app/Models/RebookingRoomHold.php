<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RebookingRoomHold extends Model
{
    protected $fillable = [
        'rebooking_id', 'booking_id', 'room_id', 'check_in', 'check_out', 'expires_at', 'released_at',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function rebooking()
    {
        return $this->belongsTo(Rebooking::class);
    }
}
