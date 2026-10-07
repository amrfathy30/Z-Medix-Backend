<?php

namespace App\Http\Resources\Api\Student;

use App\Models\ChapterPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A study page. `is_completed` is supplied by the caller, which already knows
 * the student, so the resource never runs a query of its own per page.
 *
 * @mixin ChapterPage
 */
class ChapterPageResource extends JsonResource
{
    public function __construct($resource, private bool $isCompleted = false, private bool $withContent = true)
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
            'chapter_id' => $this->chapter_id,
            'position' => $this->positionInChapter(),
            'is_completed' => $this->isCompleted,
            ...$this->withContent ? ['content' => $this->content] : [],
        ];
    }
}
