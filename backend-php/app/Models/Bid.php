<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bid extends Model
{
    use HasUuids;

    protected $fillable = ['product_id', 'buyer_id', 'seller_id', 'status', 'expires_at'];

    protected $casts = ['expires_at' => 'immutable_datetime'];

    public function revisions(): HasMany
    {
        return $this->hasMany(BidRevision::class)->orderBy('revision_number');
    }
}
