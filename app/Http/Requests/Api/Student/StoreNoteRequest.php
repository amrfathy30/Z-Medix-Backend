<?php

namespace App\Http\Requests\Api\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A note is the passage it was written about plus the student's own writing.
 * Both are required: a note with no source text could not later be placed back
 * in the page it came from.
 */
class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'selected_text' => ['required', 'string', 'max:5000'],
            'content' => ['required', 'string', 'max:5000'],
        ];
    }

    public function selectedText(): string
    {
        return $this->string('selected_text')->toString();
    }

    public function content(): string
    {
        return $this->string('content')->toString();
    }
}
