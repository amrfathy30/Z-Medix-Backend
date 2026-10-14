<?php

namespace App\Support\Fellowship;

/**
 * One spreadsheet row after mapping: the system fields it carried, its answer
 * options, its Performance Topic, and its value for each dynamic filter column
 * the import mapped.
 *
 * Staging writes one of these per row and confirmation reads it back, so the
 * workbook is opened exactly once. It therefore has to record not just the
 * values present but which filter columns the import *covered*: an update must
 * clear a filter the file mapped with an empty cell, and leave untouched one the
 * file never mentioned. `filters` holds an entry for every mapped filter column,
 * with a null value where the cell was blank — the distinction between "mapped
 * and empty" and "absent" is the whole point.
 *
 * @phpstan-type PayloadFilter array{filter_id: int|null, filter_name: string, value: string|null}
 */
final readonly class ImportRowPayload
{
    public const VERSION = 1;

    /**
     * @param  list<string>  $options  Answer option texts, in the order their columns were mapped.
     * @param  list<array{filter_id: int|null, filter_name: string, value: string|null}>  $filters
     * @param  array<string, string|null>  $raw  The row as read, keyed by header, for the side-by-side comparison.
     */
    private function __construct(
        public string $questionText,
        public ?string $explanation,
        public ?string $difficulty,
        public ?string $correctAnswer,
        public ?string $performanceTopic,
        public array $options,
        public array $filters,
        public array $raw,
        public int $version = self::VERSION,
    ) {}

    /**
     * @param  list<string>  $options
     * @param  list<array{filter_id: int|null, filter_name: string, value: string|null}>  $filters
     * @param  array<string, string|null>  $raw
     */
    public static function make(
        string $questionText,
        ?string $explanation,
        ?string $difficulty,
        ?string $correctAnswer,
        ?string $performanceTopic,
        array $options,
        array $filters,
        array $raw = [],
    ): self {
        return new self(
            questionText: $questionText,
            explanation: $explanation,
            difficulty: $difficulty,
            correctAnswer: $correctAnswer,
            performanceTopic: $performanceTopic,
            options: array_values($options),
            filters: array_values($filters),
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<string> $options */
        $options = array_values(array_map(
            static fn ($option): string => (string) $option,
            is_array($payload['options'] ?? null) ? $payload['options'] : [],
        ));

        /** @var list<array{filter_id: int|null, filter_name: string, value: string|null}> $filters */
        $filters = array_values(array_map(
            static fn (array $filter): array => [
                'filter_id' => isset($filter['filter_id']) ? (int) $filter['filter_id'] : null,
                'filter_name' => (string) ($filter['filter_name'] ?? ''),
                'value' => self::nullableString($filter['value'] ?? null),
            ],
            array_filter(
                is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
                'is_array',
            ),
        ));

        /** @var array<string, string|null> $raw */
        $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : [];

        return new self(
            questionText: (string) ($payload['question_text'] ?? ''),
            explanation: self::nullableString($payload['explanation'] ?? null),
            difficulty: self::nullableString($payload['difficulty'] ?? null),
            correctAnswer: self::nullableString($payload['correct_answer'] ?? null),
            performanceTopic: self::nullableString($payload['performance_topic'] ?? null),
            options: $options,
            filters: $filters,
            raw: $raw,
            version: (int) ($payload['version'] ?? self::VERSION),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'question_text' => $this->questionText,
            'explanation' => $this->explanation,
            'difficulty' => $this->difficulty,
            'correct_answer' => $this->correctAnswer,
            'performance_topic' => $this->performanceTopic,
            'options' => $this->options,
            'filters' => $this->filters,
            'raw' => $this->raw,
        ];
    }

    /**
     * The options with their blanks dropped: an unused option column is an empty
     * cell, not an empty answer.
     *
     * @return list<string>
     */
    public function nonEmptyOptions(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $option): string => trim($option), $this->options),
            static fn (string $option): bool => $option !== '',
        ));
    }

    /**
     * Which of the mapped options the correct answer refers to, as an index into
     * `nonEmptyOptions()`.
     *
     * A spreadsheet names its answer one of two ways, and both are accepted:
     *
     *  - by its text, repeating the answer cell's wording;
     *  - by its label — A, B, C, D, or 1, 2, 3 — addressing the option columns
     *    by the order they were mapped.
     *
     * Text wins where it is unambiguous, because text is the more explicit of
     * the two. A label is read against the mapped slots rather than against the
     * options that survived the blanks, so "C" means the third option column
     * the admin mapped even when the second one is empty on this row. Case and
     * surrounding space and punctuation are ignored; nothing else is.
     *
     * Null means the import cannot tell which option is correct — missing,
     * unrecognised, out of range, or pointing two different ways at once — and
     * the row is reported rather than written with a guess.
     */
    public function correctOptionIndex(): ?int
    {
        if ($this->correctAnswer === null || trim($this->correctAnswer) === '') {
            return null;
        }

        $byText = $this->optionIndexByText($this->correctAnswer);
        $byLabel = $this->optionIndexByLabel($this->correctAnswer);

        // Both readings, disagreeing: an option whose text is literally "A"
        // sitting somewhere other than first. Which one the admin meant is
        // anyone's guess, so neither is used.
        if ($byText !== null && $byLabel !== null && $byText !== $byLabel) {
            return null;
        }

        return $byText ?? $byLabel;
    }

    /**
     * The one option whose text is this answer. Null if none matches, and null
     * if more than one does — an answer that names two options names neither.
     */
    private function optionIndexByText(string $answer): ?int
    {
        $needle = QuestionTextNormalizer::normalize($answer);

        if ($needle === '') {
            return null;
        }

        $found = null;

        foreach ($this->nonEmptyOptions() as $index => $option) {
            if (QuestionTextNormalizer::normalize($option) !== $needle) {
                continue;
            }

            if ($found !== null) {
                return null;
            }

            $found = $index;
        }

        return $found;
    }

    /**
     * The option a label addresses, resolved against the mapped slots.
     *
     * The slot is translated into an index among the non-empty options, since
     * that is the set the question is actually written from. A label pointing at
     * a column this row left blank addresses no answer at all.
     */
    private function optionIndexByLabel(string $answer): ?int
    {
        $slot = self::labelToSlot($answer);

        if ($slot === null || $slot > count($this->options)) {
            return null;
        }

        $index = 0;

        foreach ($this->options as $position => $option) {
            $isEmpty = trim($option) === '';

            if ($position + 1 === $slot) {
                return $isEmpty ? null : $index;
            }

            if (! $isEmpty) {
                $index++;
            }
        }

        return null;
    }

    /**
     * The 1-based option column a label names, or null if it is not a label.
     *
     * Accepted: a single letter, and a positive whole number. Both may carry the
     * punctuation a spreadsheet puts around them — "A.", "(b)", "3)".
     */
    private static function labelToSlot(string $answer): ?int
    {
        $label = trim(QuestionTextNormalizer::normalize($answer), " \t.)(:-]['\"");

        if ($label === '') {
            return null;
        }

        if (preg_match('/^[a-z]$/', $label) === 1) {
            return ord($label) - ord('a') + 1;
        }

        if (preg_match('/^[0-9]+$/', $label) === 1) {
            $slot = (int) $label;

            return $slot >= 1 ? $slot : null;
        }

        return null;
    }

    public function contentHash(): string
    {
        return QuestionTextNormalizer::hash($this->questionText);
    }

    /**
     * Filter entries carrying an actual value, i.e. the ones an import should
     * set. Entries with a null value are the mapped-but-empty cells, which
     * clear rather than set.
     *
     * @return list<array{filter_id: int|null, filter_name: string, value: string|null}>
     */
    public function filtersWithValues(): array
    {
        return array_values(array_filter(
            $this->filters,
            static fn (array $filter): bool => $filter['value'] !== null && trim((string) $filter['value']) !== '',
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
