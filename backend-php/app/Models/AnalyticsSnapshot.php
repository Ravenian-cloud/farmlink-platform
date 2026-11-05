<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'type',
        'snapshot_key',
        'dimensions',
        'payload',
        'computed_at',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'payload' => 'array',
        'computed_at' => 'immutable_datetime',
    ];
}
