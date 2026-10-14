<?php

namespace App\Http\Resources\Api\Student;

use App\Models\FellowshipSessionQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of Recent Activity: a question the student answered, which session
 * it was in, and how it went.
 *
 * Read from the session question's snapshot and captured topic, so an entry
 * still reads correctly after the question behind it is reworded or deleted.
 *
 * @mixin FellowshipSessionQuestion
 */
class FellowshipActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->fellowship_quiz_session_id,
            'session_title' => $this->session?->title,
            'question_text' => $this->snapshot()->questionText,
            'performance_topic' => $this->topic_value,
            'difficulty' => $this->difficulty,
            'is_correct' => $this->is_correct,
            'answered_within_time' => $this->answered_within_time,
            'answered_at' => $this->answered_at?->toIso8601String(),
        ];
    }
}
