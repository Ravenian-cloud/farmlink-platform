<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportService extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider_id',
        'county',
        'service_area',
        'vehicle_type',
        'capacity_kg',
        'base_rate_minor',
        'currency',
        'available',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
        'base_rate_minor' => 'integer',
        'available' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
