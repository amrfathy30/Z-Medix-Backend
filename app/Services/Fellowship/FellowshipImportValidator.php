<?php

namespace App\Services\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Models\FellowshipFilterValue;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;
use App\Support\Fellowship\QuestionTextNormalizer;

/**
 * Whether one mapped spreadsheet row may become a question.
 *
 * Everything the module requires of a question is checked here, before anything
 * is inserted: the text, at least two non-empty options, exactly one correct
 * answer that actually names one of those options, a recognisable difficulty,
 * and exactly one Performance Topic.
 *
 * Reasons are returned rather than thrown. A bad row is reported against its own
 * row number and left out of the import; the valid rows beside it still go in.
 */
class FellowshipImportValidator
{
    /**
     * Problems with this row, as messages the admin can act on. An empty array
     * means the row is importable.
     *
     * @return list<string>
     */
    public function validate(ImportRowPayload $payload, ImportHeaderMap $map): array
    {
        $errors = [];

        if (trim($payload->questionText) === '') {
            $errors[] = 'Question text is empty.';
        }

        $options = $payload->nonEmptyOptions();

        if (count($options) < 2) {
            $errors[] = 'At least two non-empty answer options are required; found '.count($options).'.';
        }

        if ($this->hasDuplicateOptions($options)) {
            $errors[] = 'Two answer options are identical.';
        }

        if ($payload->correctAnswer === null) {
            $errors[] = 'Correct answer is empty.';
        } elseif (count($options) >= 2 && $payload->correctOptionIndex() === null) {
            $errors[] = 'Correct answer does not match any of this row\'s answer options.';
        }

        if ($payload->difficulty === null) {
            $errors[] = 'Difficulty is empty.';
        } elseif (DifficultyParser::parse($payload->difficulty) === null) {
            $errors[] = 'Difficulty must be one of '.DifficultyParser::allowedLabels().'.';
        }

        if ($payload->performanceTopic === null) {
            $errors[] = 'Performance topic is empty.';
        }

        return [...$errors, ...$this->filterValueLengthErrors($payload, $map)];
    }

    /**
     * Cells that name a filter value longer than the column can hold.
     *
     * Every classification an import carries — the Performance Topic and each
     * mapped dynamic filter — ends up as a row in `fellowship_filter_values`,
     * whose `value` is a `varchar(255)`. Nothing capped that length before, so
     * an over-long cell passed staging and was refused by MySQL at confirmation
     * with error 1406. The row was then caught and recorded as `failed`, which
     * loses nothing but tells the admin only that "this row could not be saved"
     * — at the end of the import, about a problem that was visible in the file
     * from the start. Checked here instead, the row is excluded while the file
     * is being reviewed, named by the column it came from, and the valid rows
     * beside it still go in.
     *
     * Measured on the value as it will be *stored*, which is the trimmed cell:
     * {@see FellowshipFilterService::resolveValue()}
     * trims and nothing else, so a cell padded out past the limit that trims
     * back under it is valid, while one whose internal whitespace would only
     * collapse under duplicate-matching normalization is not — that collapsing
     * decides which existing value a cell *matches*, never what gets written.
     *
     * Counted in characters with `mb_strlen()`, because the column is `utf8mb4`
     * and MySQL counts characters there: 255 Arabic characters are 510 bytes and
     * perfectly acceptable.
     *
     * An empty optional cell is not a value at all and is left alone here — it
     * is the instruction to clear, and clearing writes nothing.
     *
     * @return list<string>
     */
    private function filterValueLengthErrors(ImportRowPayload $payload, ImportHeaderMap $map): array
    {
        $errors = [];

        $topic = $payload->performanceTopic;

        if ($topic !== null && $this->exceedsLimit($topic)) {
            $errors[] = $this->tooLong(
                $map->headerFor(FellowshipImportMappingTarget::PerformanceTopic)
                    ?? __('admin.fellowship.target_performance_topic'),
                $topic,
            );
        }

        // Every mapped filter column that carried a value, whether it feeds a
        // filter the Fellowship already has or one this import would create:
        // both become the same row in the same column.
        foreach ($payload->filtersWithValues() as $filter) {
            $value = (string) $filter['value'];

            if (! $this->exceedsLimit($value)) {
                continue;
            }

            $errors[] = $this->tooLong(
                $filter['filter_name'] !== '' ? $filter['filter_name'] : __('admin.fellowship.target_existing_filter'),
                $value,
            );
        }

        return $errors;
    }

    private function exceedsLimit(string $value): bool
    {
        return mb_strlen(trim($value)) > FellowshipFilterValue::VALUE_MAX_LENGTH;
    }

    /**
     * The reason, naming the column the admin has to go and shorten and the
     * length it actually is, so the file can be fixed without guessing.
     */
    private function tooLong(string $filter, string $value): string
    {
        return __('admin.fellowship.import_error_filter_value_too_long', [
            'filter' => $filter,
            'length' => mb_strlen(trim($value)),
            'max' => FellowshipFilterValue::VALUE_MAX_LENGTH,
        ]);
    }

    /**
     * Two options with the same text would make "exactly one correct answer"
     * ambiguous — matching the correct answer by text could not tell them
     * apart.
     *
     * @param  list<string>  $options
     */
    private function hasDuplicateOptions(array $options): bool
    {
        $normalized = array_map(
            static fn (string $option): string => QuestionTextNormalizer::normalize($option),
            $options,
        );

        return count(array_unique($normalized)) !== count($normalized);
    }
}
