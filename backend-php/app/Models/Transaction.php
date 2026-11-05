<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'bid_id',
        'product_id',
        'buyer_id',
        'seller_id',
        'quantity',
        'unit',
        'unit_price_minor',
        'total_minor',
        'currency',
        'status',
        'payment_status',
        'escrow_status',
        'checkout_request_id',
        'merchant_request_id',
        'mpesa_receipt',
        'paid_at',
        'delivered_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price_minor' => 'integer',
        'total_minor' => 'integer',
        'paid_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
