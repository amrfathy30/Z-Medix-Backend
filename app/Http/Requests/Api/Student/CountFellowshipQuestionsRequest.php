<?php

namespace App\Http\Requests\Api\Student;

use App\Support\Fellowship\ValidatesQuestionPoolCriteria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Counting how many questions a set of narrowings would yield.
 *
 * The same narrowings a session is generated from — shared with
 * {@see GenerateFellowshipSessionRequest} through
 * {@see ValidatesQuestionPoolCriteria}, so the figure reported here is counted
 * from the pool generation draws from — but none of what generating a session
 * needs. Counting names nothing and creates nothing, so it asks for no title,
 * and the count is a question about the filters rather than about a size, so
 * `question_count` is optional too.
 *
 * `title` is still accepted and ignored: clients written against the shared
 * request send one, and refusing it now would break them for no gain.
 */
class CountFellowshipQuestionsRequest extends FormRequest
{
    use ValidatesQuestionPoolCriteria;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Accepted for backward compatibility, never read.
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Optional, and bounded by the same limits generation enforces when
            // it is given: a count the student could not generate from is worth
            // refusing here rather than reporting `can_generate: false` about.
            'question_count' => [
                'sometimes',
                'nullable',
                'integer',
                'min:'.(int) config('fellowship.session.min_question_count', 1),
                'max:'.(int) config('fellowship.session.max_question_count', 200),
            ],

            ...$this->questionPoolRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->questionPoolMessages();
    }

    /**
     * The count the student is asking whether they could generate, or null when
     * they only asked how many questions there are.
     */
    public function questionCount(): ?int
    {
        $count = $this->input('question_count');

        return $count === null || $count === '' ? null : (int) $count;
    }
}
