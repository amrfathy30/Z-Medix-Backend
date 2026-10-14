<?php

namespace App\Http\Resources\Api\Student;

use App\Http\Resources\Api\Student\Concerns\HasAnnotationContext;
use App\Models\StudentBookmark;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bookmarked passage. Shaped like {@see HighlightResource}: the chapter and
 * subject are added only for the collection screen, which lists bookmarks away
 * from the page they were made on.
 *
 * @mixin StudentBookmark
 */
class BookmarkResource extends JsonResource
{
    use HasAnnotationContext;

    public function __construct($resource, private bool $withContext = false)
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
            'chapter_page_id' => $this->chapter_page_id,
            'selected_text' => $this->selected_text,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            ...$this->withContext ? ['context' => $this->annotationContext()] : [],
        ];
    }
}
