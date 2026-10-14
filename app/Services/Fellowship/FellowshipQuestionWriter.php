<?php

namespace App\Services\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\QuizDifficulty;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;

/**
 * Turns one validated import row into a stored question — created fresh, or
 * applied over an existing one.
 *
 * Both paths live here because the difference between them is narrow and easy to
 * get wrong. Creating writes everything the row carries. Updating replaces the
 * question's own fields and its whole option set, then touches only the filters
 * this import actually mapped:
 *
 *  - a mapped column with a value sets that filter's value;
 *  - a mapped column left blank clears it, because the admin said so explicitly;
 *  - a filter the file never mentioned is left exactly as it was.
 *
 * Sessions are untouched either way: they serve their own snapshots, so an
 * update here cannot move a score a student has already earned.
 *
 * Callers wrap this in a transaction; nothing here opens one of its own, so a
 * row either lands whole or not at all along with the rest of its import.
 */
class FellowshipQuestionWriter
{
    public function __construct(private FellowshipFilterService $filters) {}

    /**
     * Insert the row as a new question.
     */
    public function create(Fellowship $fellowship, ImportRowPayload $payload, ImportHeaderMap $map): FellowshipQuestion
    {
        $this->assertAnswerSet($payload);

        $topicValueId = $this->performanceValueId($fellowship, $payload, $map);
        $this->filters->assertPerformanceTopic($fellowship, $topicValueId);

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->create([
            'question_text' => $payload->questionText,
            'explanation' => $payload->explanation,
            'difficulty' => $this->difficulty($payload),
            'performance_filter_value_id' => $topicValueId,
        ]);

        $this->writeOptions($question, $payload);
        $this->applyFilterColumns($fellowship, $question, $payload, $map);

        return $question;
    }

    /**
     * Apply the row over an existing question.
     *
     * The option set is rewritten rather than merged: an imported row is the
     * whole answer set for that question, and matching old options to new ones
     * by text would silently keep an option the admin had removed.
     */
    public function update(
        Fellowship $fellowship,
        FellowshipQuestion $question,
        ImportRowPayload $payload,
        ImportHeaderMap $map,
    ): FellowshipQuestion {
        $this->assertAnswerSet($payload);

        // An update never clears a required field: a row with no topic of its
        // own keeps the one the question already had, and the result is checked
        // to be a value of this Fellowship's performance filter either way.
        $topicValueId = $this->performanceValueId($fellowship, $payload, $map)
            ?? $question->performance_filter_value_id;

        $this->filters->assertPerformanceTopic($fellowship, $topicValueId);

        $question->update([
            'question_text' => $payload->questionText,
            'explanation' => $payload->explanation,
            'difficulty' => $this->difficulty($payload),
            'performance_filter_value_id' => $topicValueId,
        ]);

        $question->options()->delete();
        $this->writeOptions($question, $payload);

        $question->load('filterValues');
        $this->applyFilterColumns($fellowship, $question, $payload, $map);

        return $question->refresh();
    }

    /**
     * Write the row's options, the correct one flagged, in the order their
     * columns were mapped.
     */
    private function writeOptions(FellowshipQuestion $question, ImportRowPayload $payload): void
    {
        $options = $payload->nonEmptyOptions();
        $correctIndex = $payload->correctOptionIndex();

        foreach ($options as $index => $optionText) {
            $question->options()->create([
                'option_text' => $optionText,
                'is_correct' => $index === $correctIndex,
                'order' => $index + 1,
            ]);
        }
    }

    /**
     * The Performance Topic value for this row, resolved against the
     * Fellowship's single performance filter — whatever this import's column
     * happened to be called.
     */
    private function performanceValueId(Fellowship $fellowship, ImportRowPayload $payload, ImportHeaderMap $map): ?int
    {
        if ($payload->performanceTopic === null) {
            return null;
        }

        $header = $map->headerFor(FellowshipImportMappingTarget::PerformanceTopic) ?? 'Performance Topic';

        $filter = $this->filters->resolvePerformanceFilter($fellowship, $header);

        return $this->filters->resolveValue($filter, $payload->performanceTopic)->getKey();
    }

    /**
     * Set, clear or leave alone each dynamic filter this import mapped.
     *
     * Only the columns in this import's map are considered, which is what keeps
     * a question's values for filters absent from the file intact.
     */
    private function applyFilterColumns(
        Fellowship $fellowship,
        FellowshipQuestion $question,
        ImportRowPayload $payload,
        ImportHeaderMap $map,
    ): void {
        $valuesByFilterName = [];

        foreach ($payload->filters as $entry) {
            $valuesByFilterName[$entry['filter_name']] = $entry['value'];
        }

        foreach ($map->dynamicFilterColumns() as $column) {
            $filter = $this->resolveColumnFilter($fellowship, $column);

            if ($filter === null) {
                continue;
            }

            $value = $valuesByFilterName[$filter->name] ?? $valuesByFilterName[$column['filter_name'] ?? ''] ?? null;
            $value = $value !== null && trim((string) $value) !== '' ? trim((string) $value) : null;

            if ($value === null) {
                // Mapped, and empty: the admin said this question has no value
                // for this filter, so an existing one is removed.
                $question->filterValues()
                    ->where('fellowship_filter_id', $filter->getKey())
                    ->delete();

                continue;
            }

            $filterValue = $this->filters->resolveValue($filter, $value);

            // Resolved from the filter itself, so this holds by construction —
            // asserted anyway, because the pivot's unique index guards "one
            // value per filter" and nothing else guards "of that filter".
            $this->filters->assertValueBelongsToFilter($filter, $filterValue->getKey());

            $question->filterValues()->updateOrCreate(
                ['fellowship_filter_id' => $filter->getKey()],
                ['fellowship_filter_value_id' => $filterValue->getKey()],
            );
        }
    }

    /**
     * The filter one mapped column feeds. An existing filter is reused by id —
     * checked to belong to this Fellowship — and a new one is created now, at
     * confirmation time, never during staging.
     *
     * @param  array{header: string, filter_id: int|null, filter_name: string|null, is_new: bool}  $column
     */
    private function resolveColumnFilter(Fellowship $fellowship, array $column): ?FellowshipFilter
    {
        if ($column['filter_id'] !== null) {
            /** @var FellowshipFilter|null $filter */
            $filter = FellowshipFilter::query()->find($column['filter_id']);

            if ($filter === null) {
                return null;
            }

            $this->filters->assertFilterBelongsTo($fellowship, $filter);

            return $filter;
        }

        $name = $column['filter_name'];

        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->filters->resolveFilter($fellowship, $name);
    }

    /**
     * Refuse a row that could not produce an answerable question.
     *
     * Row validation already rejects these during staging; repeating the check
     * here means no caller — a retried confirmation, a future admin action —
     * can write a question a student could not answer.
     */
    private function assertAnswerSet(ImportRowPayload $payload): void
    {
        if (count($payload->nonEmptyOptions()) < 2 || $payload->correctOptionIndex() === null) {
            throw FellowshipImportException::incompleteAnswerSet();
        }
    }

    private function difficulty(ImportRowPayload $payload): QuizDifficulty
    {
        return DifficultyParser::parse($payload->difficulty) ?? QuizDifficulty::Medium;
    }
}
