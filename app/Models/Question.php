<?php

namespace App\Models;

use App\Exceptions\Learning\LastQuestionException;
use App\Models\Concerns\HasSequentialOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory, HasSequentialOrder;

    protected $fillable = [
        'quiz_id',
        'question',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function orderParentColumn(): string
    {
        return 'quiz_id';
    }

    /**
     * A quiz must never lose its last question through the application's
     * supported delete path, so the guard lives on the model rather than only
     * on the admin button that triggers it.
     */
    protected static function booted(): void
    {
        static::deleting(function (Question $question): void {
            if (! $question->canBeDeleted()) {
                throw LastQuestionException::cannotBeDeleted();
            }
        });
    }

    /**
     * False when this is the only question left on its quiz.
     */
    public function canBeDeleted(): bool
    {
        return $this->siblingQuestionCount() > 1;
    }

    /**
     * How many questions the owning quiz holds, including this one. Counted
     * against a trashed quiz too, so the guard still applies while a chapter is
     * soft-deleted.
     */
    public function siblingQuestionCount(): int
    {
        return static::query()->where('quiz_id', $this->quiz_id)->count();
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The single option flagged correct for this question.
     */
    public function correctOption(): ?QuestionOption
    {
        return $this->options->firstWhere('is_correct', true);
    }
}
