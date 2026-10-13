<?php

namespace App\Models;

use App\Support\Fellowship\FellowshipQuestionSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One question inside one session: its fixed place in the generated order, the
 * frozen copy of the question it presents, the student's final answer, and that
 * question's own advisory clock.
 *
 * Everything shown and everything graded comes from {@see snapshot()}. The
 * `question` relation is traceability only — it may be null once the source
 * question is force-deleted, and reading content from it would reintroduce
 * exactly the drift the snapshot exists to prevent.
 *
 * An answered row is final: the student cannot change an answer once it is
 * confirmed.
 */
class FellowshipSessionQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_quiz_session_id',
        'fellowship_question_id',
        'position',
        'question_snapshot',
        'difficulty',
        'performance_filter_value_id',
        'topic_value',
        'selected_option_key',
        'is_correct',
        'answered_at',
        'answered_within_time',
        'active_seconds',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'question_snapshot' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
            'answered_within_time' => 'boolean',
            'active_seconds' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuizSession::class, 'fellowship_quiz_session_id');
    }

    /**
     * The question this row was captured from, for traceability. Never a source
     * of question content.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuestion::class, 'fellowship_question_id');
    }

    /**
     * The topic value this question was filed under at generation. Kept as a
     * relation for joins; the display text lives in `topic_value` and in the
     * snapshot, so a renamed value never rewrites history.
     */
    public function performanceTopic(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilterValue::class, 'performance_filter_value_id');
    }

    /**
     * The frozen question: the only thing this row is rendered and graded from.
     */
    public function snapshot(): FellowshipQuestionSnapshot
    {
        return FellowshipQuestionSnapshot::fromArray($this->question_snapshot ?? []);
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    public function selectedOptionText(): ?string
    {
        return $this->snapshot()->optionText($this->selected_option_key);
    }

    public function correctOptionKey(): ?string
    {
        return $this->snapshot()->correctOptionKey();
    }

    public function correctOptionText(): ?string
    {
        return $this->snapshot()->optionText($this->correctOptionKey());
    }

    /**
     * Active time spent on this question, banked plus the stretch still running.
     *
     * The running stretch is measured against the given instant — the moment of
     * the request — so a student who closed the browser without pausing keeps
     * accruing time, which is the intended behaviour.
     */
    public function elapsedSeconds(?Carbon $asOf = null): int
    {
        $elapsed = (int) $this->active_seconds;

        if ($this->activated_at !== null) {
            $asOf ??= now();
            $elapsed += max(0, (int) $this->activated_at->diffInSeconds($asOf, absolute: true));
        }

        return $elapsed;
    }

    /**
     * @param  Builder<FellowshipSessionQuestion>  $query
     * @return Builder<FellowshipSessionQuestion>
     */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('answered_at');
    }

    /**
     * @param  Builder<FellowshipSessionQuestion>  $query
     * @return Builder<FellowshipSessionQuestion>
     */
    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }
}
