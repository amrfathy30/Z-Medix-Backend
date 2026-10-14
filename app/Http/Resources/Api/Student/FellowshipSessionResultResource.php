<?php

namespace App\Http\Resources\Api\Student;

use App\Models\FellowshipQuizSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A completed session's result: its totals, its per-topic breakdown, and every
 * question in order with the student's answer, the correct answer, the
 * explanation and the advisory timing verdict.
 *
 * Both the totals and the topic breakdown are scoped to this session — the same
 * student's other sessions are not part of them.
 *
 * @mixin FellowshipQuizSession
 */
class FellowshipSessionResultResource extends JsonResource
{
    /**
     * @param  array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}  $tally
     * @param  array{study_time_seconds: int, topics: list<string>, topic_performance: list<array<string, mixed>>}  $meta
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
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'study_time_seconds' => $this->meta['study_time_seconds'],
            'performance_topics' => $this->meta['topics'],
            ...$this->tally,
            'topic_performance' => $this->meta['topic_performance'],
            'questions' => FellowshipSessionQuestionResource::collection($this->questions),
        ];
    }
}
