<?php

namespace App\Http\Requests\Api\Student;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The option key is checked for shape only. Whether it is one of the answers on
 * offer is decided against the attempt question's own snapshot, not against the
 * live `question_options` table: an option the admin has since deleted must
 * still be answerable, and one they have since added must not be.
 *
 * The key is the string the attempt served as `options[].key`, echoed back
 * unchanged.
 */
class AnswerQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'option_key' => ['required', 'string'],
        ];
    }

    public function selectedOptionKey(): string
    {
        return $this->string('option_key')->toString();
    }
}
