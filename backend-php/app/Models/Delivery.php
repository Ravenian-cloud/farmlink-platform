<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'transaction_id',
        'provider_id',
        'status',
        'pickup_county',
        'delivery_county',
        'estimated_pickup_at',
        'estimated_delivery_at',
        'picked_up_at',
        'delivered_at',
    ];

    protected $casts = [
        'estimated_pickup_at' => 'immutable_datetime',
        'estimated_delivery_at' => 'immutable_datetime',
        'picked_up_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
