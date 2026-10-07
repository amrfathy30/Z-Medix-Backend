<?php

namespace App\Http\Resources\Api\Student;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full state of one attempt: its fixed question order, what the student has
 * answered so far, and the question they are on.
 *
 * The attempt carries no timer and no pass mark — the only clock the student
 * sees belongs to their study session.
 *
 * @mixin QuizAttempt
 */
class QuizAttemptResource extends JsonResource
{
    /**
     * @param  array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int}  $tally
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
            'chapter_id' => $this->quiz?->chapter_id,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            ...$this->tally,
            'current_question_id' => $this->currentQuestion()?->id,
            'questions' => QuizAttemptQuestionResource::collection($this->questions),
        ];
    }
}
