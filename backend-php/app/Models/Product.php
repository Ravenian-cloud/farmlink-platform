<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasUuids;

    protected $fillable = [
        'seller_id',
        'category',
        'name',
        'description',
        'crop',
        'input_type',
        'brand',
        'quantity_available',
        'unit',
        'price_minor',
        'currency',
        'county',
        'status',
    ];

    protected $casts = [
        'quantity_available' => 'decimal:3',
        'price_minor' => 'integer',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
