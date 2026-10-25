<?php

namespace App\Support\Fellowship;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\QuizDifficulty;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The narrowings a student may describe a Fellowship question pool with.
 *
 * Shared by the two requests that describe the same pool: generating a session
 * and counting what a set of filters would yield. Defined once so the two can
 * never drift — the count a student is shown has to be the count generation
 * would draw from, and that starts with both reading the request the same way.
 *
 * What is *not* here is what the two differ on. Generation requires a title and
 * a question count because it creates something named and of a fixed size;
 * counting requires neither, because it creates nothing.
 *
 * Every narrowing is optional and an absent one means All.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesQuestionPoolCriteria
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function questionPoolRules(): array
    {
        $fellowshipId = $this->route('fellowship')?->getKey();

        return [
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
    protected function questionPoolMessages(): array
    {
        return [
            'filters.*.*.exists' => 'One of the selected filter values does not belong to this fellowship.',
        ];
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
