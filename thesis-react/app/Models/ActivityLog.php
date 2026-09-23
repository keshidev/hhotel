<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_staff_name',
        'action',
        'action_activity',
        'model_type',
        'model_id',
        'module_page',
        'record_affected',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // Immutable — no updates or deletes allowed
    public static function boot()
    {
        parent::boot();

        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeForModule($query, string $module)
    {
        return $query->where('module_page', $module);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action_activity', 'like', "%{$action}%");
    }

    public function scopeForUser($query, string $name)
    {
        return $query->where('user_staff_name', 'like', "%{$name}%");
    }

    public function scopeDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to . ' 23:59:59']);
    }

    // ── Helpers ──────────────────────────────────────────────────
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at
            ? $this->created_at->format('F j, Y — g:i A')
            : '—';
    }

    // Renamed from hasChanges() to avoid conflict with Laravel's base Model method
    public function hasValueChanges(): bool
    {
        return !empty($this->old_values) || !empty($this->new_values);
    }
}