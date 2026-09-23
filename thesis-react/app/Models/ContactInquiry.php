<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactInquiry extends Model
{
    use HasFactory;

    public const STATUSES = ['new', 'in_progress', 'resolved', 'spam'];

    protected $fillable = [
        'reference_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'booking_reference',
        'subject',
        'message',
        'status',
        'assigned_to',
        'resolved_at',
        'resolution_message',
        'resolution_email_status',
        'resolution_email_version',
        'resolution_email_sent_at',
        'resolution_email_error',
        'submission_key',
        'source_ip_hash',
        'customer_email_status',
        'customer_email_sent_at',
        'staff_notification_status',
        'staff_notification_sent_at',
        'notification_error',
        'anonymized_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'resolution_email_version' => 'integer',
        'resolution_email_sent_at' => 'datetime',
        'customer_email_sent_at' => 'datetime',
        'staff_notification_sent_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function statusHistory()
    {
        return $this->hasMany(ContactInquiryStatusHistory::class)->latest('id');
    }

    public function getGuestNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
    }
}
