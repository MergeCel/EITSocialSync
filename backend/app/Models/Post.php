<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial', 'cancelled'];

    protected $fillable = [
        'title',
        'content_type',
        'caption',
        'hashtags',
        'status',
        'scheduled_at',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function platforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class, 'post_media')->withPivot('sort_order');
    }

    public function orderedMedia()
    {
        return $this->belongsToMany(MediaFile::class, 'post_media')
            ->withPivot('sort_order')
            ->orderBy('post_media.sort_order');
    }
}
