<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subject as the Subjects screen lists it: what it holds, and how far the
 * student has got through it.
 *
 * The counts come from `withCount` on the listing query and the completion
 * figure from one aggregate over the whole page of subjects, so a list of any
 * length costs the same handful of queries.
 *
 * @mixin Subject
 */
class SubjectListItemResource extends JsonResource
{
    public function __construct($resource, private int $completedChapters = 0)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totalChapters = (int) ($this->published_chapters_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->imageUrl(),
            'chapters_count' => $totalChapters,
            'books_count' => (int) ($this->published_books_count ?? 0),
            'chapters' => [
                'completed_chapters' => $this->completedChapters,
                'total_chapters' => $totalChapters,
                'percentage' => $totalChapters === 0
                    ? 0.0
                    : round($this->completedChapters / $totalChapters * 100, 2),
            ],
        ];
    }
}
