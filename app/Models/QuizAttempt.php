<?php

namespace App\Models;

use App\Enums\QuizAttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student run through a chapter quiz, kept as history.
 *
 * There is no pass mark and no time limit: an attempt completes when every one
 * of its questions has been answered, whatever the score.
 */
class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quiz_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuizAttemptStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * The attempt's questions in the shuffled order fixed when it was created.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizAttemptQuestion::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function isInProgress(): bool
    {
        return $this->status === QuizAttemptStatus::InProgress;
    }

    /**
     * The next question the student has to answer, or null once the attempt is
     * fully answered.
     */
    public function currentQuestion(): ?QuizAttemptQuestion
    {
        return $this->questions()->unanswered()->first();
    }

    public function hasUnansweredQuestions(): bool
    {
        return $this->questions()->unanswered()->exists();
    }

    /**
     * @param  Builder<QuizAttempt>  $query
     * @return Builder<QuizAttempt>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', QuizAttemptStatus::InProgress);
    }

    /**
     * @param  Builder<QuizAttempt>  $query
     * @return Builder<QuizAttempt>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', QuizAttemptStatus::Completed);
    }
}
