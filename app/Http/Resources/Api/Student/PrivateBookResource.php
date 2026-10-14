<?php

namespace App\Http\Resources\Api\Student;

use App\Models\StudentPrivateBook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A book in the student's own library.
 *
 * There is no file URL here on purpose: the PDF lives on the private disk and is
 * only ever served by the authenticated, ownership-checked download route, whose
 * path is given as `pdf_url`. A public URL to a student's own upload is exactly
 * what this feature must not produce.
 *
 * The file's own metadata — name, size, type — is reported so a library screen
 * can describe the upload, and so a later AI/RAG source picker has something to
 * list. Nothing inside the PDF is read.
 *
 * @mixin StudentPrivateBook
 */
class PrivateBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subject = $this->relationLoaded('subject') ? $this->getRelation('subject') : null;

        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'subject' => $subject === null ? null : [
                'id' => $subject->getKey(),
                'name' => $subject->name,
            ],
            'title' => $this->title,
            'author' => $this->author,
            'has_pdf' => $this->hasPdf(),
            'pdf_file_name' => $this->pdfFileName(),
            'pdf_size' => $this->pdfSize(),
            'pdf_mime_type' => $this->pdfMimeType(),
            'pdf_url' => route('student.private-books.pdf', ['privateBook' => $this->id]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
