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

    /**
     * Study time the student has logged, newest first.
     */
    public function studySessions(): HasMany
    {
        return $this->hasMany(StudySession::class)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * Chapters the student has completed.
     */
    public function chapterProgress(): HasMany
    {
        return $this->hasMany(StudentChapterProgress::class);
    }

    /**
     * Chapter pages the student has marked as read.
     */
    public function chapterPageProgress(): HasMany
    {
        return $this->hasMany(StudentChapterPageProgress::class);
    }

    /**
     * Every quiz run the student has made, newest first.
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * Where the student last stopped, one position per subject.
     */
    public function studyPositions(): HasMany
    {
        return $this->hasMany(StudentStudyPosition::class);
    }

    /**
     * Text the student has highlighted while reading, newest first.
     */
    public function highlights(): HasMany
    {
        return $this->hasMany(StudentHighlight::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Notes the student has written while reading, newest first.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
