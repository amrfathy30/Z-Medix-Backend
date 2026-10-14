<?php

namespace App\Support\Fellowship;

use App\Models\FellowshipQuestion;
use App\Support\Learning\QuestionSnapshot;

/**
 * An immutable copy of a Fellowship question as it stood when a session was
 * generated.
 *
 * Once a session exists this — not the live `fellowship_questions` rows — is
 * what the session is rendered and graded from, and what its analytics are
 * attributed to. An admin may then reword the question, swap its options, move
 * the correct answer, re-file its Performance Topic or delete the question
 * outright without changing what any past session shows or scored.
 *
 * Options are keyed by the id the option had at capture time. Auto-increment ids
 * are never reused, so the key stays a meaningful label after the option row is
 * gone and the client keeps echoing back the identifier it was shown. The key is
 * a value inside the JSON document, never a foreign key.
 *
 * Mirrors {@see QuestionSnapshot} in shape and intent, but
 * is a separate document: a Fellowship question carries a difficulty, a required
 * Performance Topic and any number of dynamic filter values, none of which a
 * chapter-quiz question has.
 *
 * @phpstan-type SnapshotOption array{key: string, option_text: string, is_correct: bool, order: int}
 * @phpstan-type SnapshotTopic array{filter_id: int|null, filter_name: string|null, value_id: int|null, value: string|null}
 * @phpstan-type SnapshotFilter array{filter_id: int|null, filter_name: string, value_id: int|null, value: string}
 */
final readonly class FellowshipQuestionSnapshot
{
    public const VERSION = 1;

    /**
     * @param  list<array{key: string, option_text: string, is_correct: bool, order: int}>  $options
     * @param  array{filter_id: int|null, filter_name: string|null, value_id: int|null, value: string|null}  $performanceTopic
     * @param  list<array{filter_id: int|null, filter_name: string, value_id: int|null, value: string}>  $filters
     */
    private function __construct(
        public ?int $questionId,
        public string $questionText,
        public ?string $explanation,
        public ?string $difficulty,
        public array $options,
        public array $performanceTopic,
        public array $filters,
        public int $version = self::VERSION,
    ) {}

    /**
     * Capture a question exactly as it is now — options, topic and dynamic
     * filter values included. This is the one and only moment the live rows are
     * read, so they must already be loaded or loadable.
     */
    public static function fromQuestion(FellowshipQuestion $question): self
    {
        $options = $question->options()->get()
            ->map(static fn ($option): array => [
                'key' => (string) $option->getKey(),
                'option_text' => (string) $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'order' => (int) $option->order,
            ])
            ->values()
            ->all();

        $topic = $question->performanceTopic;

        $filters = $question->filterValues()->with(['filter', 'value'])->get()
            ->filter(static fn ($pivot): bool => $pivot->filter !== null && $pivot->value !== null)
            ->map(static fn ($pivot): array => [
                'filter_id' => (int) $pivot->fellowship_filter_id,
                'filter_name' => (string) $pivot->filter->name,
                'value_id' => (int) $pivot->fellowship_filter_value_id,
                'value' => (string) $pivot->value->value,
            ])
            ->values()
            ->all();

        return new self(
            questionId: $question->getKey(),
            questionText: (string) $question->question_text,
            explanation: $question->explanation !== null && $question->explanation !== '' ? (string) $question->explanation : null,
            difficulty: $question->difficulty?->value,
            options: $options,
            performanceTopic: [
                'filter_id' => $topic?->fellowship_filter_id !== null ? (int) $topic->fellowship_filter_id : null,
                'filter_name' => $topic?->filter?->name,
                'value_id' => $topic?->getKey(),
                'value' => $topic?->value,
            ],
            filters: $filters,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        /** @var list<array{key: string, option_text: string, is_correct: bool, order: int}> $options */
        $options = array_values(array_map(
            static fn (array $option): array => [
                'key' => (string) ($option['key'] ?? ''),
                'option_text' => (string) ($option['option_text'] ?? ''),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'order' => (int) ($option['order'] ?? 0),
            ],
            array_filter(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [], 'is_array'),
        ));

        /** @var list<array{filter_id: int|null, filter_name: string, value_id: int|null, value: string}> $filters */
        $filters = array_values(array_map(
            static fn (array $filter): array => [
                'filter_id' => isset($filter['filter_id']) ? (int) $filter['filter_id'] : null,
                'filter_name' => (string) ($filter['filter_name'] ?? ''),
                'value_id' => isset($filter['value_id']) ? (int) $filter['value_id'] : null,
                'value' => (string) ($filter['value'] ?? ''),
            ],
            array_filter(is_array($snapshot['filters'] ?? null) ? $snapshot['filters'] : [], 'is_array'),
        ));

        $topic = is_array($snapshot['performance_topic'] ?? null) ? $snapshot['performance_topic'] : [];

        return new self(
            questionId: isset($snapshot['question_id']) ? (int) $snapshot['question_id'] : null,
            questionText: (string) ($snapshot['question_text'] ?? ''),
            explanation: isset($snapshot['explanation']) && $snapshot['explanation'] !== null
                ? (string) $snapshot['explanation']
                : null,
            difficulty: isset($snapshot['difficulty']) && $snapshot['difficulty'] !== null
                ? (string) $snapshot['difficulty']
                : null,
            options: $options,
            performanceTopic: [
                'filter_id' => isset($topic['filter_id']) ? (int) $topic['filter_id'] : null,
                'filter_name' => isset($topic['filter_name']) && $topic['filter_name'] !== null ? (string) $topic['filter_name'] : null,
                'value_id' => isset($topic['value_id']) ? (int) $topic['value_id'] : null,
                'value' => isset($topic['value']) && $topic['value'] !== null ? (string) $topic['value'] : null,
            ],
            filters: $filters,
            version: (int) ($snapshot['version'] ?? self::VERSION),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'question_id' => $this->questionId,
            'question_text' => $this->questionText,
            'explanation' => $this->explanation,
            'difficulty' => $this->difficulty,
            'options' => $this->options,
            'performance_topic' => $this->performanceTopic,
            'filters' => $this->filters,
        ];
    }

    /**
     * The options as the student is shown them: key and text, in the order the
     * admin had set. Correctness is deliberately absent, so this is safe to
     * return for a question the student has not answered yet.
     *
     * @return list<array{key: string, option_text: string}>
     */
    public function presentableOptions(): array
    {
        $options = $this->options;

        usort($options, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return array_values(array_map(
            static fn (array $option): array => [
                'key' => $option['key'],
                'option_text' => $option['option_text'],
            ],
            $options,
        ));
    }

    public function hasOption(string $key): bool
    {
        return $this->option($key) !== null;
    }

    /**
     * @return array{key: string, option_text: string, is_correct: bool, order: int}|null
     */
    public function option(string $key): ?array
    {
        foreach ($this->options as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Grading reads correctness from here and nowhere else.
     */
    public function isCorrect(string $key): bool
    {
        return (bool) ($this->option($key)['is_correct'] ?? false);
    }

    public function correctOptionKey(): ?string
    {
        foreach ($this->options as $option) {
            if ($option['is_correct']) {
                return $option['key'];
            }
        }

        return null;
    }

    public function optionText(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return $this->option($key)['option_text'] ?? null;
    }

    public function topicValueId(): ?int
    {
        return $this->performanceTopic['value_id'];
    }

    public function topicValue(): ?string
    {
        return $this->performanceTopic['value'];
    }
}
