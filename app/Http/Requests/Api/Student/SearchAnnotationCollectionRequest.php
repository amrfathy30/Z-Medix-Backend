<?php

namespace App\Http\Requests\Api\Student;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;

class SearchAnnotationCollectionRequest extends AnnotationCollectionRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'subject_id' => ['sometimes', 'integer', 'min:1', 'exists:subjects,id'],
            'subject_ids' => ['sometimes', 'array', 'exists:subjects,id'],
            'subject_ids.*' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * A nonempty multi-selection takes precedence over the legacy subject.
     */
    public function subject(): ?Subject
    {
        return $this->subjectIds() === [] ? parent::subject() : null;
    }

    /** @return list<int> */
    public function subjectIds(): array
    {
        return array_values(array_map('intval', $this->validated('subject_ids', [])));
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }
}
