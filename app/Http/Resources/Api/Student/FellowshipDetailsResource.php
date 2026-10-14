<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Fellowship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Fellowship dashboard: who the Fellowship is, how much of it the student
 * has done, and how well.
 *
 * Every figure here aggregates the student's confirmed answers across all of
 * their sessions in this Fellowship — including answers in sessions they have
 * paused and not finished, and counting a question answered again in a later
 * session as a second answer. A session's own result screen reports the same
 * kinds of figures scoped to that session alone.
 *
 * `topics_completed` is the number of distinct Performance Topics the student
 * has answered at least one question in; it is not how many topics they have
 * exhausted.
 *
 * @mixin Fellowship
 */
class FellowshipDetailsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $stats
     */
    public function __construct($resource, private array $stats)
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
            'name' => $this->name,
            'image' => $this->imageUrl(),
            'performance_topics' => $this->stats['performance_topics'],
            'total_questions' => $this->stats['total_questions'],
            'study_time_seconds' => $this->stats['study_time_seconds'],
            'average_score' => $this->stats['average_score'],
            'topics_completed' => $this->stats['topics_completed'],
            'day_streak' => $this->stats['day_streak'],
            'daily_study_time' => $this->stats['daily_study_time'],
            'quiz_performance' => $this->stats['quiz_performance'],
            'topic_performance' => $this->stats['topic_performance'],
            'recent_activity' => $this->stats['recent_activity'],
        ];
    }
}
