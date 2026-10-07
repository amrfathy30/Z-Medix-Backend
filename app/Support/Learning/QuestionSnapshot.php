<?php

namespace App\Support\Learning;

use App\Models\Question;

/**
 * An immutable copy of a question as it stood when a quiz attempt was created.
 *
 * Once an attempt exists, this — not the live `questions` and `question_options`
 * rows — is what the attempt is rendered and graded from. An admin may then
 * reword the question, swap its options, move the correct answer or delete the
 * whole thing without changing what any past attempt shows or scores.
 *
 * Options are keyed by the id the option had at capture time. Auto-increment ids
 * are never reused, so the key stays meaningful as a label even after the option
 * row is gone, and the client can keep sending back the same identifier it was
 * shown. The key is a value inside the JSON document, never a foreign key.
 *
 * This class owns the document's shape: nothing else should reach into the raw
 * array, so the schema can grow (an explanation, media, a question type) without
 * every reader having to know about it.
 *
 * @phpstan-type SnapshotOption array{key: string, option_text: string, is_correct: bool, order: int}
 * @phpstan-type SnapshotArray array{version: int, question_id: int|null, question: string, explanation: string|null, options: list<SnapshotOption>}
 */
final readonly class QuestionSnapshot
{
    /**
     * Stored with every snapshot so a later shape change can be read back
     * without guessing which documents predate it.
     */
    public const VERSION = 1;

    /**
     * @param  list<array{key: string, option_text: string, is_correct: bool, order: int}>  $options
     */
    private function __construct(
        public ?int $questionId,
        public string $question,
        public ?string $explanation,
        public array $options,
        public int $version = self::VERSION,
    ) {}

    /**
     * Capture a question exactly as it is now, options included.
     *
     * The question's options must already be loaded or loadable: the capture is
     * the one and only moment the live rows are read.
     */
    public static function fromQuestion(Question $question): self
    {
        $options = $question->options()->get()
            ->map(fn ($option): array => [
                'key' => (string) $option->getKey(),
                'option_text' => (string) $option->option_text,
                'is_correct' => (bool) $option->is_correct,
                'order' => (int) $option->order,
            ])
            ->values()
            ->all();

        return new self(
            questionId: $question->getKey(),
            question: (string) $question->question,
            // `questions` carries no explanation column today. The key is
            // captured regardless so snapshots taken before one is added stay
            // readable against the same shape.
            explanation: self::explanationOf($question),
            options: $options,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        /** @var list<array{key: string, option_text: string, is_correct: bool, order: int}> $options */
        $options = array_values(array_map(
            fn (array $option): array => [
                'key' => (string) ($option['key'] ?? ''),
                'option_text' => (string) ($option['option_text'] ?? ''),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'order' => (int) ($option['order'] ?? 0),
            ],
            is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [],
        ));

        return new self(
            questionId: isset($snapshot['question_id']) ? (int) $snapshot['question_id'] : null,
            question: (string) ($snapshot['question'] ?? ''),
            explanation: isset($snapshot['explanation']) ? (string) $snapshot['explanation'] : null,
            options: $options,
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
            'question' => $this->question,
            'explanation' => $this->explanation,
            'options' => $this->options,
        ];
    }

    /**
     * The options as the student is shown them: text only, in the order the
     * admin had set when the attempt was created. Correctness is deliberately
     * absent, so this can be returned for an unanswered question.
     *
     * @return list<array{key: string, option_text: string}>
     */
    public function presentableOptions(): array
    {
        $options = $this->options;

        usort($options, fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return array_values(array_map(
            fn (array $option): array => [
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

    /**
     * Reads an explanation off the question only if the model actually carries
     * one, so this keeps working unchanged when the column is introduced.
     */
    private static function explanationOf(Question $question): ?string
    {
        $explanation = $question->getAttribute('explanation');

        return is_string($explanation) && $explanation !== '' ? $explanation : null;
    }
}
