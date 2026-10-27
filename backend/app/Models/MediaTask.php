<?php

namespace App\Models;

use Database\Factories\MediaTaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTask extends Model
{
    /** @use HasFactory<MediaTaskFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'media_file_id',
        'source',
        'status',
        'progress',
        'name',
        'error_message',
    ];

    protected function casts(): array
    {
        return ['progress' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }
}
