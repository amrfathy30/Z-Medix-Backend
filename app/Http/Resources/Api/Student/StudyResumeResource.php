<?php

namespace App\Http\Resources\Api\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where the student picks a subject back up.
 *
 * `has_position` distinguishes a remembered place from the starting point
 * offered to a student who has not studied the subject yet. The student stopped
 * either on a page or inside a quiz, so `chapter_page` and `quiz_attempt` are
 * never both set.
 */
class StudyResumeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chapter = $this['chapter'];
        $page = $this['chapter_page'];
        $attempt = $this['quiz_attempt'];
        $currentQuestion = $this['current_question'];

        return [
            'subject_id' => $this['subject']->id,
            'has_position' => $this['has_position'],
            'last_active_at' => $this['last_active_at']?->toIso8601String(),
            'chapter' => $chapter === null ? null : [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'order' => $chapter->order,
            ],
            'chapter_page' => $page === null ? null : [
                'id' => $page->id,
                'position' => $page->positionInChapter(),
            ],
            'quiz_attempt' => $attempt === null ? null : [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'status' => $attempt->status?->value,
                'current_question' => $currentQuestion === null ? null : [
                    'id' => $currentQuestion->id,
                    'question_id' => $currentQuestion->question_id,
                    'position' => $currentQuestion->position,
                ],
            ],
        ];
    }
}
