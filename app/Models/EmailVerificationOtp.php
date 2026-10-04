<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use Database\Factories\EmailVerificationOtpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use email one-time passcode. Only a hash of the code is persisted;
 * the plaintext code exists solely in memory while the notification is sent.
 */
class EmailVerificationOtp extends Model
{
    /** @use HasFactory<EmailVerificationOtpFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'status',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'code_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'status' => OtpStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', OtpStatus::Pending);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }
}
