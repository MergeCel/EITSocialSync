<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PostPlatform extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_id',
        'platform',
        'is_enabled',
        'publish_status',
        'advanced_settings',
        'platform_post_id',
        'platform_url',
        'published_at',
        'error_message',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'advanced_settings' => 'array',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function engagement(): HasOne
    {
        return $this->hasOne(EngagementStat::class);
    }
}
