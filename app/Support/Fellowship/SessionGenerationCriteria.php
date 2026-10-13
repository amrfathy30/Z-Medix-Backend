<?php

namespace App\Support\Fellowship;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\QuizDifficulty;

/**
 * What a student asked a session to be generated from.
 *
 * Every narrowing is optional, and an absent one means All rather than none —
 * a filter the student did not touch must not restrict the pool.
 *
 * The combination rule is the part worth stating once, here: values chosen
 * *within* one filter are alternatives (OR), while different filters all have to
 * be satisfied (AND). "Cardiology or Neurology, and only the hard ones" is the
 * shape students expect, and it falls out of that rule.
 *
 * Stored with the session as history. It is never re-run: the session's
 * questions are fixed at generation.
 */
final readonly class SessionGenerationCriteria
{
    /**
     * @param  array<int, list<int>>  $filterValueIds  Filter id => the value ids chosen within it.
     * @param  list<QuizDifficulty>  $difficulties
     */
    private function __construct(
        public array $filterValueIds,
        public array $difficulties,
        public FellowshipQuestionStatusFilter $questionStatus,
        public bool $timerEnabled,
    ) {}

    /**
     * @param  array<int|string, array<int|string>|int|string>  $filterValueIds
     * @param  array<int, string|QuizDifficulty>  $difficulties
     */
    public static function make(
        array $filterValueIds = [],
        array $difficulties = [],
        FellowshipQuestionStatusFilter $questionStatus = FellowshipQuestionStatusFilter::All,
        bool $timerEnabled = false,
    ): self {
        $normalizedFilters = [];

        foreach ($filterValueIds as $filterId => $valueIds) {
            $ids = array_values(array_unique(array_map(
                static fn ($id): int => (int) $id,
                array_filter((array) $valueIds, static fn ($id): bool => $id !== null && $id !== ''),
            )));

            // A filter present but with nothing chosen is the same as untouched:
            // All, and no restriction.
            if ($ids === []) {
                continue;
            }

            $normalizedFilters[(int) $filterId] = $ids;
        }

        $normalizedDifficulties = [];

        foreach ($difficulties as $difficulty) {
            $case = $difficulty instanceof QuizDifficulty
                ? $difficulty
                : QuizDifficulty::tryFrom((string) $difficulty);

            if ($case !== null && ! in_array($case, $normalizedDifficulties, true)) {
                $normalizedDifficulties[] = $case;
            }
        }

        return new self(
            filterValueIds: $normalizedFilters,
            difficulties: $normalizedDifficulties,
            questionStatus: $questionStatus,
            timerEnabled: $timerEnabled,
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    public static function fromArray(array $criteria): self
    {
        return self::make(
            filterValueIds: is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [],
            difficulties: is_array($criteria['difficulties'] ?? null) ? $criteria['difficulties'] : [],
            questionStatus: FellowshipQuestionStatusFilter::tryFrom((string) ($criteria['question_status'] ?? ''))
                ?? FellowshipQuestionStatusFilter::All,
            timerEnabled: (bool) ($criteria['timer_enabled'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filterValueIds,
            'difficulties' => array_map(static fn (QuizDifficulty $d): string => $d->value, $this->difficulties),
            'question_status' => $this->questionStatus->value,
            'timer_enabled' => $this->timerEnabled,
        ];
    }

    /**
     * @return list<string>
     */
    public function difficultyValues(): array
    {
        return array_map(static fn (QuizDifficulty $difficulty): string => $difficulty->value, $this->difficulties);
    }

    public function hasDifficulties(): bool
    {
        return $this->difficulties !== [];
    }

    public function hasFilters(): bool
    {
        return $this->filterValueIds !== [];
    }
}
