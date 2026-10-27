<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MediaFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'folder_id',
        'original_filename',
        'custom_name',
        'path',
        'thumbnail_path',
        'mime_type',
        'size',
        'kind',
        'duration',
        'width',
        'height',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')->withPivot('sort_order');
    }

    public function dimensions(): string
    {
        if ($this->width && $this->height) {
            return "{$this->width} x {$this->height}";
        }

        return $this->kind === 'video' ? 'Video' : 'Belum dianalisis';
    }
}
