<?php

namespace App\Models;

use Database\Factories\BufferConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BufferConnection extends Model
{
    /** @use HasFactory<BufferConnectionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'api_key',
        'api_key_fingerprint',
        'buffer_account_id',
        'account_name',
        'account_email',
        'organization_id',
        'organization_name',
        'status',
        'last_synced_at',
        'last_error',
    ];

    protected $hidden = ['api_key', 'api_key_fingerprint'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
