<?php

namespace App\Http\Requests\Api\Student;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\QuizDifficulty;
use App\Support\Fellowship\SessionGenerationCriteria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
 */
class GenerateFellowshipSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $fellowshipId = $this->route('fellowship')?->getKey();

        return [
            'title' => ['required', 'string', 'max:255'],
            'question_count' => [
                'required',
                'integer',
                'min:'.(int) config('fellowship.session.min_question_count', 1),
                'max:'.(int) config('fellowship.session.max_question_count', 200),
            ],
            'timer_enabled' => ['sometimes', 'boolean'],

            'difficulties' => ['sometimes', 'array'],
            'difficulties.*' => ['string', Rule::enum(QuizDifficulty::class)],

            'question_status' => ['sometimes', Rule::enum(FellowshipQuestionStatusFilter::class)],

            'filters' => ['sometimes', 'array'],
            'filters.*' => ['array'],
            'filters.*.*' => [
                'integer',
                // Scoped to this Fellowship's own filters: a value id from
                // another Fellowship is rejected here rather than silently
                // matching nothing.
                Rule::exists('fellowship_filter_values', 'id')->where(
                    fn ($query) => $query->whereIn(
                        'fellowship_filter_id',
                        fn ($filters) => $filters->select('id')
                            ->from('fellowship_filters')
                            ->where('fellowship_id', $fellowshipId),
                    ),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filters.*.*.exists' => 'One of the selected filter values does not belong to this fellowship.',
        ];
    }

    public function title(): string
    {
        return trim($this->string('title')->toString());
    }

    public function questionCount(): int
    {
        return $this->integer('question_count');
    }

    public function criteria(): SessionGenerationCriteria
    {
        /** @var array<int|string, array<int|string>> $filters */
        $filters = $this->input('filters', []);

        return SessionGenerationCriteria::make(
            filterValueIds: is_array($filters) ? $filters : [],
            difficulties: (array) $this->input('difficulties', []),
            questionStatus: FellowshipQuestionStatusFilter::tryFrom((string) $this->input('question_status', ''))
                ?? FellowshipQuestionStatusFilter::All,
            timerEnabled: $this->boolean('timer_enabled'),
        );
    }
}
