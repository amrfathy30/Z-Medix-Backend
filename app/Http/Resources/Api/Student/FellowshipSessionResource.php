<?php

namespace App\Http\Resources\Api\Student;

use App\Models\FellowshipQuizSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full state of one session: its fixed question order, what the student has
 * answered, and the question they are on.
 *
 * `current_position` and `current_question_id` are what a resumed session opens
 * on — persisted rather than inferred, so reviewing earlier answers back and
 * forth does not move the student's place.
 *
 * `question_time_limit_seconds` is the limit this session was generated under,
 * not the platform's current one: raising the limit later must not re-judge a
 * session already running. It is null when timing was disabled.
 *
 * @mixin FellowshipQuizSession
 */
class FellowshipSessionResource extends JsonResource
{
    /**
     * @param  array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}  $tally
     * @param  array{study_time_seconds: int, topics: list<string>}  $meta
     */
    public function __construct($resource, private array $tally, private array $meta)
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
            'fellowship_id' => $this->fellowship_id,
            'title' => $this->title,
            'status' => $this->status?->value,
            'question_count' => $this->question_count,
            'current_position' => $this->current_position,
            'current_question_id' => $this->currentQuestion()?->id,
            'timer_enabled' => (bool) $this->timer_enabled,
            'question_time_limit_seconds' => $this->question_time_limit_seconds,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'study_time_seconds' => $this->meta['study_time_seconds'],
            'performance_topics' => $this->meta['topics'],
            ...$this->tally,
            'questions' => FellowshipSessionQuestionResource::collection($this->questions),
        ];
    }
}
