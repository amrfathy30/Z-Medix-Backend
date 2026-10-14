<?php

namespace App\Http\Requests\Api\Student;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new book for the student's own library: the subject it is filed under, how
 * the student describes it, and the PDF itself.
 *
 * Only PDF is in scope. The accepted type and the size ceiling come from
 * `config/media.php`, the same limits the admin uploads are held to, so there
 * is one place the platform's document rules live.
 *
 * `exists` establishes only that the subject is real; whether the student may
 * file under it is the access service's decision.
 */
class StorePrivateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'pdf' => [
                'required',
                'file',
                'mimetypes:'.implode(',', config('media.document_mime_types', ['application/pdf'])),
                'mimes:pdf',
                'max:'.(int) config('media.max_document_size_kb', 10 * 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pdf.mimetypes' => 'The book must be a PDF file.',
            'pdf.mimes' => 'The book must be a PDF file.',
        ];
    }

    public function subject(): Subject
    {
        return Subject::query()->findOrFail($this->integer('subject_id'));
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    public function author(): ?string
    {
        $author = $this->string('author')->trim()->toString();

        return $author === '' ? null : $author;
    }
}
