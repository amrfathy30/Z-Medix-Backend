<?php

namespace App\Models;

use App\Enums\FellowshipSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student run through a generated set of Fellowship questions.
 *
 * A student may hold any number of these at once. There is no Finish Early: the
 * session completes only once every generated question carries a confirmed
 * answer, and until then it is either `in_progress` or `paused` and resumes on
 * the question it stopped on, with the same questions in the same order.
 */
class FellowshipQuizSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fellowship_id',
        'title',
        'status',
        'question_count',
        'current_position',
        'timer_enabled',
        'question_time_limit_seconds',
        'generation_criteria',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FellowshipSessionStatus::class,
            'question_count' => 'integer',
            'current_position' => 'integer',
            'timer_enabled' => 'boolean',
            'question_time_limit_seconds' => 'integer',
            'generation_criteria' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fellowship(): BelongsTo
    {
        return $this->belongsTo(Fellowship::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FellowshipSessionQuestion::class, 'fellowship_quiz_session_id')
            ->orderBy('position');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FellowshipSessionPeriod::class, 'fellowship_quiz_session_id')
            ->orderBy('started_at');
    }

    public function isInProgress(): bool
    {
        return $this->status === FellowshipSessionStatus::InProgress;
    }

    public function isPaused(): bool
    {
        return $this->status === FellowshipSessionStatus::Paused;
    }

    public function isCompleted(): bool
    {
        return $this->status === FellowshipSessionStatus::Completed;
    }

    /**
     * The question the student is on: the one at the persisted position, which
     * is what a resumed session opens.
     */
    public function currentQuestion(): ?FellowshipSessionQuestion
    {
        /** @var FellowshipSessionQuestion|null $question */
        $question = $this->questions()
            ->where('position', $this->current_position)
            ->first();

        return $question;
    }

    /**
     * The open activity period, or null while the session is paused or done.
     */
    public function openPeriod(): ?FellowshipSessionPeriod
    {
        /** @var FellowshipSessionPeriod|null $period */
        $period = $this->periods()->open()->first();

        return $period;
    }

    public function hasUnansweredQuestions(): bool
    {
        return $this->questions()->unanswered()->exists();
    }

    /**
     * @param  Builder<FellowshipQuizSession>  $query
     * @return Builder<FellowshipQuizSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FellowshipSessionStatus::InProgress->value,
            FellowshipSessionStatus::Paused->value,
        ]);
    }

    /**
     * @param  Builder<FellowshipQuizSession>  $query
     * @return Builder<FellowshipQuizSession>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', FellowshipSessionStatus::Completed);
    }
}
