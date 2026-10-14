<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Subject;
use App\Services\Learning\SubjectAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Subject results screen: the student's overall result in this subject, how
 * far they have read, how long they have spent, and how each chapter quiz has
 * gone.
 *
 * Every figure is counted from the student's own recorded activity at request
 * time — see {@see SubjectAnalyticsService} for the rules
 * behind each one. Nothing here is stored or estimated.
 *
 * `study_time_seconds` is the canonical figure and `study_time_hours` the same
 * number for display; `daily_study_time` carries both per day.
 *
 * @mixin Subject
 */
class SubjectStatisticsResource extends JsonResource
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
            'subject_id' => $this->id,
            'subject_name' => $this->name,
            'overall_result' => $this->stats['overall_result'],
            'chapters' => $this->stats['chapters'],
            'reading_progress' => $this->stats['reading_progress'],
            'study_time_seconds' => $this->stats['study_time_seconds'],
            'study_time_hours' => $this->stats['study_time_hours'],
            'daily_study_time' => $this->stats['daily_study_time'],
            'day_streak' => $this->stats['day_streak'],
            'quiz_performance' => $this->stats['quiz_performance'],
        ];
    }
}
