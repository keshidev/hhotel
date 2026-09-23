<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RebookingRefund extends Model
{
    protected $fillable = [
        'rebooking_id', 'booking_id', 'source_payment_id', 'refund_payment_id', 'amount', 'status',
        'recipient_name', 'recipient_account', 'recipient_account_last_four', 'gcash_reference',
        'normalized_gcash_reference', 'processed_at', 'processed_by', 'reason', 'proof_disk',
        'proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size', 'proof_sha256',
        'proof_retention_expires_at', 'proof_deleted_at',
    ];

    protected $hidden = ['recipient_account', 'proof_path', 'proof_sha256'];

    protected $casts = [
        'amount' => 'decimal:2',
        'recipient_account' => 'encrypted',
        'processed_at' => 'datetime',
        'proof_retention_expires_at' => 'datetime',
        'proof_deleted_at' => 'datetime',
    ];

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
