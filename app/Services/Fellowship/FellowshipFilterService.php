<?php

namespace App\Services\Fellowship;

use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use App\Support\Fellowship\QuestionTextNormalizer;

/**
 * Creating and reusing a Fellowship's dynamic filters and their values.
 *
 * Filters are discovered from spreadsheets rather than declared, which makes
 * reuse the central concern: an import that names a filter or a value the
 * Fellowship already has must land on the existing row, not fork it, or Topic
 * Performance silently splits a topic in two. Matching is therefore done on the
 * normalized name — "Cardiology", "cardiology " and "Cardiology" from three
 * different files are one value.
 *
 * Every method here is scoped to one Fellowship, and a filter or value from
 * another Fellowship is rejected rather than quietly reused: filters are
 * per-Fellowship, and a cross-Fellowship reference would make a question
 * answerable to a classification its own Fellowship does not have.
 */
class FellowshipFilterService
{
    /**
     * Find the Fellowship's filter with this name, matched case- and
     * whitespace-insensitively, or null.
     */
    public function findFilterByName(Fellowship $fellowship, string $name): ?FellowshipFilter
    {
        $needle = QuestionTextNormalizer::normalize($name);

        return $fellowship->filters()
            ->get()
            ->first(fn (FellowshipFilter $filter): bool => QuestionTextNormalizer::normalize($filter->name) === $needle);
    }

    /**
     * The Fellowship's filter with this name, created if it has none.
     *
     * Called only while an import is being confirmed — staging never publishes
     * a filter, so an abandoned preview leaves no trace.
     */
    public function resolveFilter(Fellowship $fellowship, string $name, bool $isPerformance = false): FellowshipFilter
    {
        $name = trim($name);

        $filter = $this->findFilterByName($fellowship, $name);

        if ($filter === null) {
            /** @var FellowshipFilter $filter */
            $filter = $fellowship->filters()->create([
                'name' => $name,
                'is_performance' => $isPerformance,
            ]);

            return $filter;
        }

        // A filter already in use as something else is never repurposed: the
        // Performance Topic designation is set once, by the first import that
        // maps one, and later imports map onto that same filter.
        if ($isPerformance && ! $filter->is_performance) {
            $filter->update(['is_performance' => true]);
        }

        return $filter;
    }

    /**
     * The Fellowship's Performance Topic filter, designated if it has none yet.
     *
     * The header feeding it may be spelled differently in every import; what
     * must not change is which filter it feeds. Once `performance_filter_id` is
     * set, the mapped column lands on that filter whatever the admin called it
     * in the file.
     */
    public function resolvePerformanceFilter(Fellowship $fellowship, string $headerName): FellowshipFilter
    {
        $existing = $fellowship->performanceFilter;

        if ($existing !== null) {
            return $existing;
        }

        $filter = $this->resolveFilter($fellowship, $headerName, isPerformance: true);

        $fellowship->update(['performance_filter_id' => $filter->getKey()]);
        $fellowship->setRelation('performanceFilter', $filter);

        return $filter;
    }

    /**
     * Bring the Fellowship's designation into line with this filter's own flag.
     *
     * The designation is one fact held on two rows — `filters.is_performance`
     * and `fellowships.performance_filter_id` — so whichever side was just
     * written, this is what makes the other agree. An import writes both
     * through {@see resolvePerformanceFilter()}; an admin editing the filter
     * directly writes only the flag, and this follows it.
     *
     * A Fellowship has exactly one Performance Topic classification, so
     * designating a filter withdraws the designation from any other. Whether
     * the move is allowed at all is decided before the write, where the admin
     * can be told why — every question's required topic is a value of the
     * filter being moved away from.
     */
    public function applyPerformanceDesignation(Fellowship $fellowship, FellowshipFilter $filter): void
    {
        $this->assertFilterBelongsTo($fellowship, $filter);

        if (! $filter->is_performance) {
            if ($fellowship->performance_filter_id === $filter->getKey()) {
                $fellowship->update(['performance_filter_id' => null]);
                $fellowship->setRelation('performanceFilter', null);
            }

            return;
        }

        $fellowship->filters()
            ->whereKeyNot($filter->getKey())
            ->where('is_performance', true)
            ->update(['is_performance' => false]);

        if ($fellowship->performance_filter_id !== $filter->getKey()) {
            $fellowship->update(['performance_filter_id' => $filter->getKey()]);
        }

        $fellowship->setRelation('performanceFilter', $filter);
    }

    /**
     * The filter's value with this text, created if it has none.
     *
     * The length check guards the creation and not the lookup, which is the
     * distinction that matters: an existing value is necessarily within the
     * column's limit already, so a cell that merely *matches* one — after the
     * whitespace collapsing normalization does for matching — writes nothing and
     * needs no guard. Only a value about to be inserted has to fit.
     *
     * The import rejects an over-long cell long before this, at staging, where
     * it can name the column and exclude the row while the admin is still
     * looking at the file. This is the backstop for every other caller.
     */
    public function resolveValue(FellowshipFilter $filter, string $value): FellowshipFilterValue
    {
        $value = trim($value);
        $needle = QuestionTextNormalizer::normalize($value);

        $existing = $filter->values()
            ->get()
            ->first(fn (FellowshipFilterValue $candidate): bool => QuestionTextNormalizer::normalize($candidate->value) === $needle);

        if ($existing !== null) {
            return $existing;
        }

        // Characters, not bytes: the column is utf8mb4, so 255 Arabic
        // characters fit and `strlen()` would refuse them.
        $length = mb_strlen($value);

        if ($length > FellowshipFilterValue::VALUE_MAX_LENGTH) {
            throw FellowshipImportException::filterValueTooLong(
                $filter->name,
                $length,
                FellowshipFilterValue::VALUE_MAX_LENGTH,
            );
        }

        /** @var FellowshipFilterValue $created */
        $created = $filter->values()->create(['value' => $value]);

        return $created;
    }

    /**
     * Refuse a filter that belongs to a different Fellowship.
     *
     * The mapping step offers only this Fellowship's filters, so reaching here
     * means a tampered-with or stale form submission.
     */
    public function assertFilterBelongsTo(Fellowship $fellowship, FellowshipFilter $filter): void
    {
        if ($filter->fellowship_id !== $fellowship->getKey()) {
            throw FellowshipImportException::filterFromAnotherFellowship();
        }
    }

    /**
     * Refuse a value whose filter belongs to a different Fellowship.
     */
    public function assertValueBelongsTo(Fellowship $fellowship, FellowshipFilterValue $value): void
    {
        $filter = $value->filter;

        if ($filter === null || $filter->fellowship_id !== $fellowship->getKey()) {
            throw FellowshipImportException::filterFromAnotherFellowship();
        }
    }

    /**
     * Refuse a value that is not one of the given filter's own.
     *
     * Checking the filter's Fellowship is not enough on its own: two filters of
     * the same Fellowship are still two different classifications, and pairing
     * one filter's id with another's value would file a question under a value
     * that filter does not offer.
     */
    public function assertValueBelongsToFilter(FellowshipFilter $filter, int $valueId): void
    {
        if (! $filter->values()->whereKey($valueId)->exists()) {
            throw FellowshipImportException::valueFromAnotherFilter();
        }
    }

    /**
     * Refuse a Performance Topic that is not a value of this Fellowship's
     * designated performance filter.
     */
    public function assertPerformanceTopic(Fellowship $fellowship, ?int $valueId): void
    {
        if ($valueId === null) {
            throw FellowshipImportException::missingPerformanceTopic();
        }

        $filter = $fellowship->performanceFilter;

        if ($filter === null || ! $filter->values()->whereKey($valueId)->exists()) {
            throw FellowshipImportException::topicFromAnotherFilter();
        }
    }
}
