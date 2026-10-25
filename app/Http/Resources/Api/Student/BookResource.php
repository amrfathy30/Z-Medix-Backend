<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Book;
use App\Models\StudentPrivateBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform book opened.
 *
 * A PDF book hands over its file; a content book hands over an ordered page
 * index without bodies. Each body is read through the individual page endpoint.
 *
 * `pages_count` is the book's length whichever kind it is — counted from the
 * page rows of a content book, the stored extracted count for a PDF one, and
 * null when its PDF count is unknown. No files are parsed during API requests.
 *
 * The PDF URL is the platform book's existing public media URL — unchanged by
 * the student API. A student's own uploads are a different thing entirely and
 * are served privately; see {@see StudentPrivateBook}.
 *
 * @mixin Book
 */
class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPdf = $this->isPdf();

        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'title' => $this->title,
            'author' => $this->author,
            'classification' => $this->classification,
            'type' => $this->type?->value,
            'order' => $this->order,
            'has_pdf' => $isPdf && $this->hasPdf(),
            'pdf_url' => $isPdf ? $this->pdfUrl() : null,
            'pdf_file_name' => $isPdf ? $this->pdfFileName() : null,
            'pages_count' => $this->totalPages(),
            'cover_image' => $this->coverUrl(),
            'pages' => $isPdf ? [] : $this->pages->map(fn ($page, int $index) => new BookPageResource(
                $page,
                $index + 1,
                withContent: false,
            )),
        ];
    }
}
