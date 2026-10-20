<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FcmToken extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'token',
        'device_type',
        'device_id',
        'app_type',
        'is_active',
        'last_used_at',
    ];

    /**
     * The deterministic lookup key for a token string.
     */
    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Derives `token_hash` whenever `token` is written.
     *
     * @return Attribute<string, array<string, string>>
     */
    protected function token(): Attribute
    {
        return Attribute::set(fn (string $value): array => [
            'token' => $value,
            'token_hash' => self::hashFor($value),
        ]);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
