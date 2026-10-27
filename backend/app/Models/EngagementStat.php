<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementStat extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_platform_id',
        'views',
        'likes',
        'comments',
        'shares',
        'saves',
        'raw',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'saves' => 'integer',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }
}
