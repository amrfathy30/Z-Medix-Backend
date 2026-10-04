<?php

namespace App\Models;

use Database\Factories\PasswordResetRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use password reset token. Only the SHA-256 hash is persisted; the
 * plaintext exists in memory just long enough to be returned to the client.
 */
class PasswordResetRequest extends Model
{
    /** @use HasFactory<PasswordResetRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnused(Builder $query): void
    {
        $query->whereNull('used_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
