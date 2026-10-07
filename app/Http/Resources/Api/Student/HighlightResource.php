<?php

namespace App\Http\Resources\Api\Student;

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
        ];
    }
}
