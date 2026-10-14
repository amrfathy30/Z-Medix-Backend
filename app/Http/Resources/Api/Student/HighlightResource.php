<?php

namespace App\Http\Resources\Api\Student;

use App\Http\Resources\Api\Student\Concerns\HasAnnotationContext;
use App\Models\StudentHighlight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * There is one highlight style, so nothing about appearance is exposed. The
 * chapter and subject are not repeated: the client already knows the page it
 * asked about.
 *
 * @mixin StudentHighlight
 */
class HighlightResource extends JsonResource
{
    use HasAnnotationContext;

    /**
     * `$withContext` adds the chapter and subject the annotation sits in, for
     * the collection screen that lists them away from their page. Off by
     * default, so the per-page payload is unchanged.
     */
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
