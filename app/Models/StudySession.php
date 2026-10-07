<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of study time a student opened on a subject.
 *
 * Nothing closes a session but the student: there is no inactivity timeout, no
 * auto-close, and no limit on how many may be open at once.
 */
class StudySession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject_id',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * @param  Builder<StudySession>  $query
     * @return Builder<StudySession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @param  Builder<StudySession>  $query
     * @return Builder<StudySession>
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }
}
