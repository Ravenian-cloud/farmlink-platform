<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidRevision extends Model
{
    use HasUuids;

    protected $fillable = ['bid_id', 'created_by', 'quantity', 'unit_price_minor', 'currency', 'message'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_price_minor' => 'integer'];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }
}
