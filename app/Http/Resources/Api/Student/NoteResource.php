<?php

namespace App\Http\Resources\Api\Student;

use App\Models\StudentNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The passage is served alongside the note so the client can show what was
 * written about without re-reading the page.
 *
 * @mixin StudentNote
 */
class NoteResource extends JsonResource
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
            'content' => $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
