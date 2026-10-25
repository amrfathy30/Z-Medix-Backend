<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform book as a list row: enough to decide whether to open it, and in
 * which reader. `type` says which — a `pdf` book is opened as a file, a
 * `content` book as a sequence of pages.
 *
 * No page bodies and no file here; {@see BookResource} and the page endpoint
 * serve those.
 *
 * @mixin Book
 */
class BookListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'author' => $this->author,
            // Free text the admin types, not an entity: no id and no options.
            'classification' => $this->classification,
            'type' => $this->type?->value,
            'order' => $this->order,
            'has_pdf' => $this->isPdf() && $this->hasPdf(),
            // Content page rows or the stored PDF count; unknown PDF counts are null.
            'pages_count' => $this->totalPages(),
            'cover_image' => $this->coverUrl(),
            // Nothing records which page of a book a student has read — page
            // completion is tracked for chapter pages only — so there is no
            // progress to report for either kind of book. Null, never zero: a
            // zero would claim the student has read none of it.
            'progress_percentage' => null,
            'subject' => $this->whenLoaded('subject', fn (): array => [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
            ]),
        ];
    }
}
