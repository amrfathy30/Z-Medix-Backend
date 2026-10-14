<?php

namespace App\Support\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Models\FellowshipImportRow;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\DifficultyParser;

/**
 * The side-by-side an admin decides a duplicate row from.
 *
 * Duplicates are matched on normalized question text, so the texts are rarely
 * what differs — the interesting question is whether the *answers* agree. This
 * puts the stored question and the uploaded row next to each other field by
 * field and marks the fields that conflict, so a row whose options or correct
 * answer have changed stands out from one that is merely the same question
 * uploaded twice.
 *
 * Filter values are included on both sides, with the ones this import did not
 * map shown as absent rather than as empty — on an update those are preserved,
 * and the admin should be able to see that before choosing.
 *
 * @phpstan-type Field array{label: string, existing: string|null, imported: string|null, conflicts: bool, in_import: bool}
 */
final readonly class DuplicateComparison
{
    /**
     * @param  list<array{label: string, existing: string|null, imported: string|null, conflicts: bool, in_import: bool}>  $fields
     * @param  list<array{existing: string|null, imported: string|null, existing_is_correct: bool, imported_is_correct: bool, conflicts: bool}>  $options
     */
    private function __construct(
        public array $fields,
        public array $options,
        public bool $optionsConflict,
        public bool $correctAnswerConflicts,
    ) {}

    public static function build(FellowshipImportRow $row, ?FellowshipQuestion $existing): self
    {
        $payload = $row->payload();
        $map = ImportHeaderMap::fromArray($row->import?->header_map ?? []);

        $existing?->loadMissing(['options', 'performanceTopic.filter', 'filterValues.filter', 'filterValues.value']);

        $fields = [
            self::field(
                'Question text',
                $existing?->question_text,
                $payload->questionText,
                inImport: true,
            ),
            self::field(
                'Title',
                $existing?->title,
                $payload->title,
                // Absent from the file means an update preserves the stored
                // title, so it is shown as not in the import rather than as a
                // blank that would overwrite.
                inImport: $map->has(FellowshipImportMappingTarget::Title),
            ),
            self::field(
                'Explanation',
                $existing?->explanation,
                $payload->explanation,
                inImport: $map->has(FellowshipImportMappingTarget::Explanation),
            ),
            self::field(
                'Difficulty',
                $existing?->difficulty?->value,
                DifficultyParser::parse($payload->difficulty)?->value,
                inImport: true,
            ),
            self::field(
                'Performance topic',
                $existing?->performanceTopic?->value,
                $payload->performanceTopic,
                inImport: true,
            ),
        ];

        foreach (self::filterFields($existing, $payload, $map) as $field) {
            $fields[] = $field;
        }

        $options = self::options($existing, $payload);

        return new self(
            fields: $fields,
            options: $options,
            optionsConflict: self::anyOptionConflict($options),
            correctAnswerConflicts: self::correctAnswerConflicts($existing, $payload),
        );
    }

    /**
     * True when anything at all differs, which is what distinguishes a genuine
     * conflict from the same question uploaded again unchanged.
     */
    public function hasConflicts(): bool
    {
        if ($this->optionsConflict || $this->correctAnswerConflicts) {
            return true;
        }

        foreach ($this->fields as $field) {
            if ($field['conflicts']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{label: string, existing: string|null, imported: string|null, conflicts: bool, in_import: bool}
     */
    private static function field(string $label, ?string $existing, ?string $imported, bool $inImport): array
    {
        return [
            'label' => $label,
            'existing' => $existing,
            'imported' => $imported,
            // A field the import did not map cannot conflict: an update leaves
            // it alone, so flagging it would be misleading.
            'conflicts' => $inImport && ! self::sameText($existing, $imported),
            'in_import' => $inImport,
        ];
    }

    /**
     * One row per filter, covering the union of what the question has and what
     * the import mapped.
     *
     * @return list<array{label: string, existing: string|null, imported: string|null, conflicts: bool, in_import: bool}>
     */
    private static function filterFields(?FellowshipQuestion $existing, ImportRowPayload $payload, ImportHeaderMap $map): array
    {
        $importedByName = [];

        foreach ($payload->filters as $entry) {
            $importedByName[QuestionTextNormalizer::normalize($entry['filter_name'])] = $entry;
        }

        $mappedNames = [];

        foreach ($map->dynamicFilterColumns() as $column) {
            $name = (string) ($column['filter_name'] ?? $column['header']);
            $mappedNames[QuestionTextNormalizer::normalize($name)] = $name;
        }

        $fields = [];
        $covered = [];

        foreach ($existing?->filterValues ?? [] as $pivot) {
            $name = (string) ($pivot->filter?->name ?? '');
            $key = QuestionTextNormalizer::normalize($name);
            $covered[$key] = true;

            $inImport = isset($mappedNames[$key]);

            $fields[] = self::field(
                $name,
                $pivot->value?->value,
                $inImport ? ($importedByName[$key]['value'] ?? null) : null,
                inImport: $inImport,
            );
        }

        foreach ($mappedNames as $key => $name) {
            if (isset($covered[$key])) {
                continue;
            }

            $fields[] = self::field(
                $name,
                null,
                $importedByName[$key]['value'] ?? null,
                inImport: true,
            );
        }

        return $fields;
    }

    /**
     * The two option sets lined up by position, which is how the import reads
     * them, with the correct one marked on each side.
     *
     * @return list<array{existing: string|null, imported: string|null, existing_is_correct: bool, imported_is_correct: bool, conflicts: bool}>
     */
    private static function options(?FellowshipQuestion $existing, ImportRowPayload $payload): array
    {
        $existingOptions = ($existing?->options ?? collect())->values();
        $importedOptions = $payload->nonEmptyOptions();
        $correctIndex = $payload->correctOptionIndex();

        $rows = [];
        $count = max($existingOptions->count(), count($importedOptions));

        for ($index = 0; $index < $count; $index++) {
            $existingOption = $existingOptions->get($index);
            $importedOption = $importedOptions[$index] ?? null;

            $rows[] = [
                'existing' => $existingOption?->option_text,
                'imported' => $importedOption,
                'existing_is_correct' => (bool) ($existingOption?->is_correct ?? false),
                'imported_is_correct' => $correctIndex !== null && $correctIndex === $index,
                'conflicts' => ! self::sameText($existingOption?->option_text, $importedOption),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{existing: string|null, imported: string|null, existing_is_correct: bool, imported_is_correct: bool, conflicts: bool}>  $options
     */
    private static function anyOptionConflict(array $options): bool
    {
        foreach ($options as $option) {
            if ($option['conflicts']) {
                return true;
            }
        }

        return false;
    }

    private static function correctAnswerConflicts(?FellowshipQuestion $existing, ImportRowPayload $payload): bool
    {
        $existingCorrect = $existing?->options->firstWhere('is_correct', true)?->option_text;
        $index = $payload->correctOptionIndex();
        $importedCorrect = $index !== null ? ($payload->nonEmptyOptions()[$index] ?? null) : null;

        return ! self::sameText($existingCorrect, $importedCorrect);
    }

    /**
     * Compared the same way duplicates are matched, so trailing space and
     * capitalisation are never reported as a conflict.
     */
    private static function sameText(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return QuestionTextNormalizer::normalize($left) === QuestionTextNormalizer::normalize($right);
    }
}
