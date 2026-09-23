<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInquiryStatusHistory extends Model
{
    protected $fillable = [
        'contact_inquiry_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'actor_role',
    ];

    public function inquiry()
    {
        return $this->belongsTo(ContactInquiry::class, 'contact_inquiry_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
