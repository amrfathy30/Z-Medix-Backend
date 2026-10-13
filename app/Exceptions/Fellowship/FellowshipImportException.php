<?php

namespace App\Exceptions\Fellowship;

use RuntimeException;

/**
 * An import that cannot proceed as a whole — as opposed to a single bad row,
 * which is reported against that row and excluded.
 *
 * Admin-facing: these surface as a Filament notification rather than as an API
 * error envelope, so this is a plain runtime exception and not one of the
 * student API's coded failures.
 */
class FellowshipImportException extends RuntimeException
{
    public static function unreadableFile(string $reason = ''): self
    {
        return new self(trim(__('admin.fellowship.import_error_unreadable_file').' '.$reason));
    }

    public static function emptyFile(): self
    {
        return new self(__('admin.fellowship.import_error_empty_file'));
    }

    /**
     * A header row with fewer columns than one question needs.
     */
    public static function tooFewColumns(int $required, int $found): self
    {
        return new self(__('admin.fellowship.import_error_too_few_columns', [
            'required' => $required,
            'found' => $found,
        ]));
    }

    public static function noDataRows(): self
    {
        return new self('The uploaded file has a header row but no question rows.');
    }

    public static function tooManyRows(int $max): self
    {
        return new self("The uploaded file has more than {$max} rows. Split it and import the parts separately.");
    }

    /**
     * The targets are reported by their admin-facing labels rather than by
     * their stored values, so an Arabic panel does not show a list of English
     * field keys inside an Arabic sentence.
     *
     * @param  list<string>  $missing
     */
    public static function incompleteMapping(array $missing): self
    {
        return new self(__('admin.fellowship.import_error_incomplete_mapping', [
            'fields' => implode(__('admin.fellowship.list_separator'), array_map(
                static fn (string $target): string => self::targetLabel($target),
                $missing,
            )),
        ]));
    }

    /**
     * More than one system field was given the same column of the file.
     */
    public static function duplicateColumnAssignment(string $header): self
    {
        return new self(__('admin.fellowship.import_error_duplicate_column', ['column' => $header]));
    }

    /**
     * One target's admin-facing label, falling back to the stored value for a
     * pseudo-target such as the unnamed new filter.
     */
    private static function targetLabel(string $target): string
    {
        $key = 'admin.fellowship.target_'.$target;
        $label = __($key);

        return $label === $key ? __('admin.fellowship.import_error_target_'.$target) : $label;
    }

    /**
     * A confirmation that failed for a reason the module does not model. The
     * real exception is reported to the log; this is what the admin is shown.
     */
    public static function confirmationFailed(): self
    {
        return new self(__('admin.fellowship.import_error_confirmation_failed'));
    }

    public static function notStaged(): self
    {
        return new self('This import is not awaiting confirmation.');
    }

    public static function duplicateFilterName(string $name): self
    {
        return new self(__('admin.fellowship.import_error_duplicate_filter', ['filter' => $name]));
    }

    public static function filterFromAnotherFellowship(): self
    {
        return new self('A filter from a different fellowship cannot be used here.');
    }

    public static function valueFromAnotherFilter(): self
    {
        return new self('That value does not belong to the filter it was assigned to.');
    }

    /**
     * Every question carries exactly one Performance Topic, so a row that would
     * leave one without is refused rather than written half-classified.
     */
    public static function missingPerformanceTopic(): self
    {
        return new self('A question cannot be saved without a performance topic.');
    }

    public static function topicFromAnotherFilter(): self
    {
        return new self("That performance topic does not belong to this fellowship's performance filter.");
    }

    public static function incompleteAnswerSet(): self
    {
        return new self('A question needs at least two answer options with exactly one marked correct.');
    }

    /**
     * A filter value longer than its column can hold.
     *
     * Row validation rejects these while the file is being staged, naming the
     * column they came from; this is the backstop at the one place a value row
     * is created, so no caller — a retried confirmation, a future admin action —
     * can get as far as MySQL refusing it with error 1406.
     */
    public static function filterValueTooLong(string $filter, int $length, int $max): self
    {
        return new self(__('admin.fellowship.import_error_filter_value_too_long', [
            'filter' => $filter,
            'length' => $length,
            'max' => $max,
        ]));
    }

    /**
     * Confirmation re-checks the admin's decisions, so an undecided duplicate
     * stops the import here even if the review screen was bypassed.
     */
    public static function undecidedDuplicates(int $count): self
    {
        return new self(
            $count === 1
                ? 'One duplicate row is still awaiting a decision.'
                : "{$count} duplicate rows are still awaiting a decision."
        );
    }

    public static function decisionNotAvailable(): self
    {
        return new self('That decision is not available for this row.');
    }

    /**
     * Update Existing is only offered against a question that can still be
     * identified; by confirmation time it may have been force-deleted.
     */
    public static function updateTargetMissing(): self
    {
        return new self('The question this row would have updated no longer exists.');
    }
}
