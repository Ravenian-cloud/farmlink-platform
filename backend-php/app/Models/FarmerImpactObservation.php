<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerImpactObservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'farmer_id',
        'reporting_period',
        'county',
        'baseline_monthly_income_minor',
        'current_monthly_income_minor',
        'baseline_post_harvest_loss_percent',
        'current_post_harvest_loss_percent',
        'currency',
        'consented_at',
    ];

    protected $casts = [
        'reporting_period' => 'immutable_date',
        'baseline_monthly_income_minor' => 'integer',
        'current_monthly_income_minor' => 'integer',
        'baseline_post_harvest_loss_percent' => 'decimal:2',
        'current_post_harvest_loss_percent' => 'decimal:2',
        'consented_at' => 'immutable_datetime',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'farmer_id');
    }
}
