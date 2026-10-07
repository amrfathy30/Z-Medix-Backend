<?php

namespace App\Http\Requests\Api\Student;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartStudySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Existence only; whether the student may study the subject is the
            // access service's decision, not validation's.
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
        ];
    }

    public function subject(): Subject
    {
        return Subject::query()->findOrFail($this->integer('subject_id'));
    }
}
