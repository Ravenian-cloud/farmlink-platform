<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaPurchaseIntent extends Model
{
    use HasUuids;

    protected $fillable = [
        'subscription_id',
        'ad_campaign_id',
        'user_id',
        'amount_minor',
        'currency',
        'status',
        'checkout_request_id',
        'merchant_request_id',
        'receipt',
        'paid_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'paid_at' => 'immutable_datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
