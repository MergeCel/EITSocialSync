<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory, HasUuids;

    public const PLATFORMS = ['instagram', 'tiktok', 'facebook'];

    protected $fillable = [
        'platform',
        'account_name',
        'handle',
        'internal_id',
        'page_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'status',
        'followers',
        'metadata',
        'connected_by',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'followers' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isConnected(): bool
    {
        return $this->effectiveStatus() === 'connected';
    }

    public function effectiveStatus(): string
    {
        if (($this->metadata['provider'] ?? null) === 'buffer') {
            return $this->status === 'connected' ? 'connected' : $this->status;
        }

        if (blank($this->access_token)) {
            return 'disconnected';
        }

        if ($this->status === 'error') {
            return 'error';
        }

        if ($this->token_expires_at !== null && $this->token_expires_at->isPast()) {
            return 'expired';
        }

        return $this->status === 'expired' ? 'expired' : 'connected';
    }
}
