<?php

namespace App\Http\Requests\Api\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The passage the student picked out. Shape only: whether the page may be
 * annotated at all is the access service's decision, not validation's.
 */
class StoreHighlightRequest extends FormRequest
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
        ];
    }

    public function selectedText(): string
    {
        return $this->string('selected_text')->toString();
    }
}
