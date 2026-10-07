<?php

namespace App\Models;

use App\Support\Learning\QuestionSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question as it appears inside one attempt: its fixed position in that
 * attempt's shuffled order, the frozen copy of the question it presents, and
 * the student's final answer to it.
 *
 * Everything shown and everything graded comes from {@see snapshot()}. The
 * `question` relation is traceability only — it may be null once the original
 * question is deleted, and reading content from it would reintroduce exactly
 * the drift the snapshot exists to prevent.
 *
 * An answered row is immutable: the student cannot change an answer once it is
 * submitted.
 */
class QuizAttemptQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'question_id',
        'position',
        'question_snapshot',
        'selected_option_key',
        'is_correct',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'question_snapshot' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    /**
     * The question this row was captured from, for traceability. Null once that
     * question has been deleted; never a source of question content.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The frozen question: the only thing this row is rendered and graded from.
     */
    public function snapshot(): QuestionSnapshot
    {
        return QuestionSnapshot::fromArray($this->question_snapshot ?? []);
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /**
     * The option text the student chose, read back out of the snapshot so it
     * survives the live option being edited or deleted.
     */
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
     * @param  Builder<QuizAttemptQuestion>  $query
     * @return Builder<QuizAttemptQuestion>
     */
    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    /**
     * @param  Builder<QuizAttemptQuestion>  $query
     * @return Builder<QuizAttemptQuestion>
     */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('answered_at');
    }
}
