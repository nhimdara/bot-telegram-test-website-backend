<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'status',
        'amount',
        'currency',
        'khqr_payload',
        'md5',
        'transaction_hash',
        'provider_response',
        'expires_at',
        'paid_at',
    ];

    protected $hidden = ['khqr_payload', 'provider_response'];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_response' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
