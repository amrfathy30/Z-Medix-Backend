<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a student last stopped inside a subject. One row per student per
 * subject, replaced as they move rather than appended to.
 */
class StudentStudyPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject_id',
        'chapter_id',
        'chapter_page_id',
        'quiz_attempt_id',
        'quiz_attempt_question_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function chapterPage(): BelongsTo
    {
        return $this->belongsTo(ChapterPage::class);
    }

    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class);
    }

    public function quizAttemptQuestion(): BelongsTo
    {
        return $this->belongsTo(QuizAttemptQuestion::class);
    }

    /**
     * True when the student stopped inside a quiz rather than on a page.
     */
    public function isInsideQuiz(): bool
    {
        return $this->quiz_attempt_id !== null;
    }
}
