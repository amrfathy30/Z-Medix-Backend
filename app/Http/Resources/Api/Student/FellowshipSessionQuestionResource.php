<?php

namespace App\Http\Resources\Api\Student;

use App\Models\FellowshipSessionQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question inside a session, rendered entirely from the session's frozen
 * snapshot. The live question is never read here, so a reworded question or a
 * deleted option cannot change what a session shows.
 *
 * The correct answer is withheld until the student has answered: options carry
 * only their key and text, and `correct_option_key`, `is_correct` and the
 * explanation appear only on an answered question. That is the single place
 * correctness is revealed, so an unanswered question cannot leak it.
 *
 * `answered_within_time` is informational. It says whether a timed question was
 * answered inside its limit and is null when the session was generated with
 * timing off; it takes no part in `is_correct` or in any score.
 *
 * @mixin FellowshipSessionQuestion
 */
class FellowshipSessionQuestionResource extends JsonResource
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
            // force-deleted. Answers are submitted against this row's `id`.
            'fellowship_question_id' => $this->fellowship_question_id,
            'question_text' => $snapshot->questionText,
            'difficulty' => $this->difficulty,
            'performance_topic' => [
                'id' => $this->performance_filter_value_id,
                'value' => $this->topic_value,
            ],
            'filters' => $snapshot->filters,
            'options' => $snapshot->presentableOptions(),
            'is_answered' => $isAnswered,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'selected_option_key' => $this->selected_option_key,
            'selected_option_text' => $this->selectedOptionText(),
            'is_correct' => $isAnswered ? $this->is_correct : null,
            'correct_option_key' => $isAnswered ? $this->correctOptionKey() : null,
            'correct_option_text' => $isAnswered ? $this->correctOptionText() : null,
            'explanation' => $isAnswered ? $snapshot->explanation : null,
            'answered_within_time' => $this->answered_within_time,
            // Active time already spent on this question, so a resumed session
            // can show the remaining time rather than restarting the clock.
            'elapsed_seconds' => $this->elapsedSeconds(),
        ];
    }
}
