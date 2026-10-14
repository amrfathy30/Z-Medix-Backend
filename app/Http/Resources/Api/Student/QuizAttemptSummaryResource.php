<?php

namespace App\Http\Resources\Api\Student;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a chapter's attempt history: when the attempt ran and how it
 * scored, without its questions.
 *
 * The questions stay out deliberately — a history list is for choosing which
 * attempt to review, and the attempt endpoint serves the frozen questions and
 * answers of the one chosen.
 *
 * @mixin QuizAttempt
 */
class QuizAttemptSummaryResource extends JsonResource
{
    /**
     * @param  array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, score: int, percentage: float, average_score: float|null}  $tally
     */
    public function __construct($resource, private array $tally)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            // Read through the eager-loaded quiz, the same way
            // {@see QuizAttemptResource} does: an attempt carries no chapter
            // column of its own.
            'chapter_id' => $this->quiz?->chapter_id,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            ...$this->tally,
        ];
    }
}
