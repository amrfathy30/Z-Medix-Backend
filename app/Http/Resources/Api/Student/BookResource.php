<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Book;
use App\Models\StudentPrivateBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform book opened.
 *
 * A PDF book hands over its file; a content book says how many pages it has, to
 * be read through the page endpoint. The other type's fields are null rather
 * than absent, so the client's shape does not change between the two.
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
            'type' => $this->type?->value,
            'order' => $this->order,
            'has_pdf' => $isPdf && $this->hasPdf(),
            'pdf_url' => $isPdf ? $this->pdfUrl() : null,
            'pdf_file_name' => $isPdf ? $this->pdfFileName() : null,
            'pages_count' => $isPdf ? null : $this->pagesCount(),
        ];
    }
}
