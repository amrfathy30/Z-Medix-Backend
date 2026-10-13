<?php

namespace App\Support\Fellowship;

use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;

/**
 * Persists the per-filter selects of {@see FellowshipQuestionFormFields}.
 *
 * They are not columns and not a Filament relationship, so they are applied
 * after the question is written: a chosen value is set, a cleared one is
 * removed, and a filter not on the form is left alone.
 *
 * The Fellowship is taken from the question itself, so a value belonging to
 * another Fellowship's filter cannot be attached here.
 */
trait SavesQuestionFilterValues
{
    /**
     * Pull the filter selects out of a form payload, leaving the question's own
     * attributes behind.
     *
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, filters: array<int, int|null>}
     */
    protected function extractFilterValues(array $data): array
    {
        $filters = [];
        $prefix = FellowshipQuestionFormFields::FILTER_FIELD_PREFIX;

        foreach ($data as $key => $value) {
            if (! str_starts_with((string) $key, $prefix)) {
                continue;
            }

            $filterId = (int) substr((string) $key, strlen($prefix));

            if ($filterId > 0) {
                $filters[$filterId] = $value !== null && $value !== '' ? (int) $value : null;
            }

            unset($data[$key]);
        }

        return ['data' => $data, 'filters' => $filters];
    }

    /**
     * @param  array<int, int|null>  $filters
     */
    protected function saveFilterValues(FellowshipQuestion $question, array $filters): void
    {
        /** @var Fellowship|null $fellowship */
        $fellowship = $question->fellowship;

        if ($fellowship === null) {
            return;
        }

        $ownFilters = $fellowship->filters()->get()->keyBy(fn (FellowshipFilter $filter): int => (int) $filter->getKey());

        foreach ($filters as $filterId => $valueId) {
            /** @var FellowshipFilter|null $filter */
            $filter = $ownFilters->get((int) $filterId);

            // A filter from another Fellowship is not this question's to carry.
            if ($filter === null) {
                continue;
            }

            if ($valueId === null) {
                $question->filterValues()->where('fellowship_filter_id', $filterId)->delete();

                continue;
            }

            // Owning the filter is not enough: the value has to be one that
            // filter actually offers. Pairing one filter's id with another's
            // value would file the question under a value it does not have.
            if (! $filter->values()->whereKey($valueId)->exists()) {
                continue;
            }

            $question->filterValues()->updateOrCreate(
                ['fellowship_filter_id' => $filterId],
                ['fellowship_filter_value_id' => $valueId],
            );
        }
    }
}
