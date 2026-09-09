<?php

namespace App\Models;

use App\Enums\PhoneStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PhoneNumber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'country_id',
        'country_iso2',
        'country_code',
        'national_number',
        'e164_number',
        'is_primary',
        'status',
        'verified_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PhoneStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function otps(): HasMany
    {
        return $this->hasMany(PhoneVerificationOtp::class);
    }
}
