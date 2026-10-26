<?php

namespace App\Http\Requests\Api\Student;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StudentSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100']];
    }
}
