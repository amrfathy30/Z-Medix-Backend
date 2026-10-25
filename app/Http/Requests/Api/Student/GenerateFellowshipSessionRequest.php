<?php

namespace App\Http\Requests\Api\Student;

use App\Support\Fellowship\ValidatesQuestionPoolCriteria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generating a session. The Fellowship comes from the route, never from the
 * body: the student generates from the Fellowship they opened.
 *
 * Only the title and the question count are required. Every narrowing is
 * optional, and omitting one means All — so a request with nothing but a title
 * and a count is valid and draws from the whole bank.
 *
 * `filters` is keyed by filter id, each holding the value ids chosen within it.
 * Only values belonging to this Fellowship are accepted, which is validation's
 * part of keeping a question answerable to its own Fellowship's classifications.
 *
 * The narrowings themselves are shared with
 * {@see CountFellowshipQuestionsRequest} through
 * {@see ValidatesQuestionPoolCriteria}, so the pool the form is told about and
 * the pool this draws from are described by one set of rules.
 */
class GenerateFellowshipSessionRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'question_count' => [
                'required',
                'integer',
                'min:'.(int) config('fellowship.session.min_question_count', 1),
                'max:'.(int) config('fellowship.session.max_question_count', 200),
            ],

            ...$this->questionPoolRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->questionPoolMessages();
    }

    public function title(): string
    {
        return trim($this->string('title')->toString());
    }

    public function questionCount(): int
    {
        return $this->integer('question_count');
    }
}
