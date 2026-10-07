<?php

namespace App\Http\Resources\Api\Student;

use App\Models\QuizAttemptQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question inside an attempt, rendered entirely from the attempt's frozen
 * snapshot. The live question is never read here, so a reworded question or a
 * deleted option cannot change what a past attempt shows.
 *
 * The correct answer is withheld until the student has answered: options carry
 * only their key and text, and `correct_option_key` together with `is_correct`
 * appear only on an answered question. That is the single place correctness is
 * revealed, so an unanswered question can never leak it.
 *
 * @mixin QuizAttemptQuestion
 */
class QuizAttemptQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot();
        $isAnswered = $this->isAnswered();

        return [
            'id' => $this->id,
            'position' => $this->position,
            // Traceability back to the question captured, null once it is
            // deleted. Answers are submitted against this row's `id`.
            'question_id' => $this->question_id,
            'question' => $snapshot->question,
            // Always present for a stable response shape; `questions` carries no
            // explanation column yet, so this is null until one is added.
            'explanation' => $snapshot->explanation,
            'options' => $snapshot->presentableOptions(),
            'is_answered' => $isAnswered,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'selected_option_key' => $this->selected_option_key,
            'selected_option_text' => $this->selectedOptionText(),
            'is_correct' => $isAnswered ? $this->is_correct : null,
            'correct_option_key' => $isAnswered ? $this->correctOptionKey() : null,
            'correct_option_text' => $isAnswered ? $this->correctOptionText() : null,
        ];
    }
}
