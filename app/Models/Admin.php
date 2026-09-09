<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Concerns\HasPhoneNumbers;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable implements FilamentUser
{
    use HasFactory, HasPhoneNumbers, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'admin';

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'locale',
        'email',
        'email_verified_at',
        'password',
        'type',
        'status',
        'department',
        'job_title',
        'two_factor_enabled',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'type' => AdminType::class,
            'status' => AccountStatus::class,
            'two_factor_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }

        return $this->name ?? '';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function fcmTokens(): MorphMany
    {
        return $this->morphMany(FcmToken::class, 'tokenable');
    }
}
