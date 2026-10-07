<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chapter as the student sees it: its page list without page bodies, its
 * quiz summary, and progress figures counted at request time rather than
 * stored.
 *
 * @mixin Chapter
 */
class ChapterResource extends JsonResource
{
    /**
     * @param  array{completed_pages: int, total_pages: int, percentage: float}  $readingProgress
     * @param  array<int, int>  $completedPageIds
     */
    public function __construct(
        $resource,
        private array $readingProgress,
        private bool $isCompleted,
        private array $completedPageIds = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quiz = $this->quiz;

        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'order' => $this->order,
            'is_completed' => $this->isCompleted,
            'reading_progress' => $this->readingProgress,
            'pages' => $this->pages->map(fn ($page) => new ChapterPageResource(
                $page,
                in_array($page->getKey(), $this->completedPageIds, true),
                withContent: false,
            )),
            'quiz' => $quiz === null ? null : [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'difficulty' => $quiz->difficulty?->value,
                'question_count' => $quiz->questions()->count(),
            ],
        ];
    }
}
