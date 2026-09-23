<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualGcashConfiguration extends Model
{
    public const PRIMARY_KEY = 'primary';

    protected $fillable = [
        'configuration_key',
        'merchant_name',
        'account_name',
        'account_number',
        'qr_disk',
        'qr_path',
        'qr_original_name',
        'qr_mime_type',
        'qr_size',
        'qr_width',
        'qr_height',
        'qr_sha256',
        'configured_at',
        'configured_by',
    ];

    protected $casts = [
        'qr_size' => 'integer',
        'qr_width' => 'integer',
        'qr_height' => 'integer',
        'configured_at' => 'datetime',
    ];

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
