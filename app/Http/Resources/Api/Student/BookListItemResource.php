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
            'type' => $this->type?->value,
            'order' => $this->order,
            'has_pdf' => $this->isPdf() && $this->hasPdf(),
            // Present for a content book, null for a PDF one: a PDF book keeps
            // its material in its single file, not in page rows.
            'pages_count' => $this->isContent() ? (int) ($this->pages_count ?? $this->pagesCount()) : null,
        ];
    }
}
