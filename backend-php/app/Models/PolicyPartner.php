<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PolicyPartner extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'counties', 'key_hash', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'counties' => 'array',
        'last_used_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];
}
