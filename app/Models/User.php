<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Models\Concerns\HasPhoneNumbers;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Nnjeim\World\Models\Country;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia, MustVerifyEmail
{
    use Billable, HasApiTokens, HasFactory, HasPhoneNumbers, InteractsWithMedia, Notifiable, SoftDeletes;

    /** Single-file media collection holding the student's profile photo. */
    public const PROFILE_PHOTO_COLLECTION = 'profile_photo';

    /** @use HasFactory<UserFactory> */

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'country_id',
        'institution',
        'field_of_study',
        'password',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PROFILE_PHOTO_COLLECTION)
            ->useDisk('filament_public')
            ->singleFile();
    }

    public function fcmTokens(): MorphMany
    {
        return $this->morphMany(FcmToken::class, 'tokenable');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function emailVerificationOtps(): HasMany
    {
        return $this->hasMany(EmailVerificationOtp::class);
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }
}
