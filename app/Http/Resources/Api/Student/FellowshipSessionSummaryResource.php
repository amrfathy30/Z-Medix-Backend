<?php

namespace App\Http\Resources\Api\Student;

use App\Enums\FellowshipSessionStatus;
use App\Models\FellowshipQuizSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of Session History.
 *
 * `can_resume` and `can_view_result` say which of the two things the row offers:
 * an unfinished session reopens on its current question, a finished one shows
 * its result. They are derived from the status rather than left to the frontend
 * so both agree on what a paused session does.
 *
 * Every figure here is scoped to this session. The Fellowship-level equivalents
 * aggregate across all of the student's sessions and are reported separately.
 *
 * @mixin FellowshipQuizSession
 */
class FellowshipSessionSummaryResource extends JsonResource
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
        $isCompleted = $this->status === FellowshipSessionStatus::Completed;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status?->value,
            'performance_topics' => $this->meta['topics'],
            'total_questions' => $this->tally['total_questions'],
            'answered_questions' => $this->tally['answered_questions'],
            'correct_answers' => $this->tally['correct_answers'],
            'incorrect_answers' => $this->tally['incorrect_answers'],
            'average_score' => $this->tally['average_score'],
            'study_time_seconds' => $this->meta['study_time_seconds'],
            'current_position' => $this->current_position,
            'can_resume' => ! $isCompleted,
            'can_view_result' => $isCompleted,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
