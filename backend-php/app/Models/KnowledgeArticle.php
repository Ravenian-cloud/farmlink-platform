<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeArticle extends Model
{
    use HasUuids;

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'summary',
        'body',
        'content_type',
        'video_url',
        'status',
        'published_at',
    ];

    protected $casts = ['published_at' => 'immutable_datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
