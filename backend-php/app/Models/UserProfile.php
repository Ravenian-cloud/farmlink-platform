<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasUuids;

    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $fillable = ['farm_name', 'business_name', 'county'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
