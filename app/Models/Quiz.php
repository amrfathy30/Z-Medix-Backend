<?php

namespace App\Models;

use App\Enums\QuizDifficulty;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Difficulty used when a quiz is created outside the admin form, e.g. the
     * backfill command for chapters that predate the one-quiz-per-chapter rule.
     */
    public const DEFAULT_DIFFICULTY = QuizDifficulty::Medium;

    protected $fillable = [
        'chapter_id',
        'title',
        'difficulty',
    ];

    protected function casts(): array
    {
        return [
            'difficulty' => QuizDifficulty::class,
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * A quiz is only usable for student study once it holds a question.
     */
    public function hasQuestions(): bool
    {
        return $this->questions()->exists();
    }

    /**
     * The subject a quiz belongs to, derived through its chapter.
     */
    public function subject(): ?Subject
    {
        return $this->chapter?->subject;
    }
}
