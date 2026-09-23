<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Feedback extends Model
{  
    protected $table = 'feedbacks';
    
    protected $fillable = [
        'booking_id',
        'rating_cleanliness',
        'rating_comfort',
        'rating_staff',
        'rating_facilities',
        'rating_overall',
        'review',
        'has_issue',
        'issue_type',
        'would_recommend',
        'token',
        'token_expires_at',
        'is_submitted',
        'submitted_at',
        'admin_reply',
        'replied_at',
        'replied_by',
        'is_featured',

    ];

    protected $casts = [
        'rating_cleanliness' => 'integer',
        'rating_comfort'     => 'integer',
        'rating_staff'       => 'integer',
        'rating_facilities'  => 'integer',
        'rating_overall'     => 'integer',
        'has_issue'          => 'boolean',
        'would_recommend'    => 'boolean',
        'is_submitted'       => 'boolean',
        'token_expires_at'   => 'datetime',
        'submitted_at'       => 'datetime',
        'replied_at'         => 'datetime',
        'is_featured'        => 'boolean',

    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function repliedBy()
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function getAverageRatingAttribute(): float
    {
        $ratings = array_filter([
            $this->rating_cleanliness,
            $this->rating_comfort,
            $this->rating_staff,
            $this->rating_facilities,
            $this->rating_overall,
        ]);

        if (empty($ratings)) return 0;

        return round(array_sum($ratings) / count($ratings), 1);
    }
}
