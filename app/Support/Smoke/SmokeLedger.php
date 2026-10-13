<?php

namespace App\Support\Smoke;

/**
 * The running record of a smoke scenario's checks.
 *
 * Unlike a fail-fast run, a scenario sweep has to keep going: one broken rule
 * must not hide the thirty rules after it, and the report is only useful if it
 * says how many checks passed, how many failed and how many were never reached.
 * So a failed check is recorded with what was expected and what actually
 * happened, and the sweep continues.
 *
 * `SKIP` is kept distinct from `FAIL` on purpose. A check that could not be
 * attempted — because the data it needed was never produced — is not evidence
 * that the behaviour is wrong, and reporting it as a failure would overstate
 * what the run found just as badly as reporting it as a pass would understate
 * it.
 *
 * @phpstan-type LedgerEntry array{group: string, label: string, result: string, detail: string|null, response: string|null}
 */
class SmokeLedger
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const SKIP = 'SKIP';

    /** @var list<array{group: string, label: string, result: string, detail: string|null, response: string|null}> */
    private array $entries = [];

    private string $group = 'General';

    /**
     * Ids and counts worth printing at the end of the run.
     *
     * @var array<string, int|string|null>
     */
    private array $notes = [];

    public function group(string $group): void
    {
        $this->group = $group;
    }

    public function currentGroup(): string
    {
        return $this->group;
    }

    /**
     * Record a check. Returns the condition, so a caller can branch on whether
     * it is worth going on.
     */
    public function check(string $label, bool $condition, string $detail = '', ?SmokeApiResponse $response = null): bool
    {
        $this->entries[] = [
            'group' => $this->group,
            'label' => $label,
            'result' => $condition ? self::PASS : self::FAIL,
            'detail' => $condition ? null : $detail,
            'response' => $condition || $response === null ? null : $response->describe(),
        ];

        return $condition;
    }

    /**
     * Record an equality check, phrasing the failure as expected versus actual
     * so the report never says only that something was wrong.
     */
    public function same(string $label, mixed $expected, mixed $actual, ?SmokeApiResponse $response = null): bool
    {
        return $this->check(
            $label,
            $expected === $actual,
            sprintf('expected %s, got %s', self::stringify($expected), self::stringify($actual)),
            $response,
        );
    }

    /**
     * Record a numeric equality check.
     *
     * Scores travel through JSON, where a whole-number percentage comes back as
     * an integer and a fractional one as a float, so comparing them strictly
     * would fail on exactly the values that are easiest to get right. Compared
     * within a tolerance far finer than the two decimal places the module
     * rounds to.
     */
    public function sameNumber(string $label, int|float|null $expected, mixed $actual, ?SmokeApiResponse $response = null): bool
    {
        if ($expected === null || $actual === null) {
            return $this->same($label, $expected, $actual, $response);
        }

        if (! is_int($actual) && ! is_float($actual)) {
            return $this->check(
                $label,
                false,
                sprintf('expected the number %s, got %s', self::stringify($expected), self::stringify($actual)),
                $response,
            );
        }

        return $this->check(
            $label,
            abs((float) $expected - (float) $actual) < 0.001,
            sprintf('expected %s, got %s', self::stringify($expected), self::stringify($actual)),
            $response,
        );
    }

    public function pass(string $label): void
    {
        $this->check($label, true);
    }

    public function fail(string $label, string $detail, ?SmokeApiResponse $response = null): void
    {
        $this->check($label, false, $detail, $response);
    }

    /**
     * Record a check that could not be attempted, and why.
     */
    public function skip(string $label, string $reason): void
    {
        $this->entries[] = [
            'group' => $this->group,
            'label' => $label,
            'result' => self::SKIP,
            'detail' => $reason,
            'response' => null,
        ];
    }

    /**
     * Run a closure, recording an unexpected exception as a failure rather than
     * letting it end the sweep.
     *
     * An exception the scenario *expects* is caught by the scenario itself; what
     * reaches here is something the module did not model, which is exactly what
     * must be reported rather than swallowed.
     */
    public function attempt(string $label, callable $body): mixed
    {
        try {
            return $body();
        } catch (\Throwable $exception) {
            $this->fail($label, sprintf(
                'unexpected %s: %s (%s:%d)',
                class_basename($exception),
                $exception->getMessage(),
                basename($exception->getFile()),
                $exception->getLine(),
            ));

            return null;
        }
    }

    public function note(string $label, int|string|null $value): void
    {
        $this->notes[$label] = $value;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return list<array{group: string, label: string, result: string, detail: string|null, response: string|null}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<array{group: string, label: string, result: string, detail: string|null, response: string|null}>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['result'] === self::FAIL,
        ));
    }

    /**
     * @return list<array{group: string, label: string, result: string, detail: string|null, response: string|null}>
     */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['result'] === self::SKIP,
        ));
    }

    public function countOf(string $result): int
    {
        return count(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['result'] === $result,
        ));
    }

    /**
     * Per-group tallies, in the order the groups were first used.
     *
     * @return array<string, array{PASS: int, FAIL: int, SKIP: int}>
     */
    public function byGroup(): array
    {
        $groups = [];

        foreach ($this->entries as $entry) {
            $groups[$entry['group']] ??= [self::PASS => 0, self::FAIL => 0, self::SKIP => 0];
            $groups[$entry['group']][$entry['result']]++;
        }

        return $groups;
    }

    public function hasFailures(): bool
    {
        return $this->countOf(self::FAIL) > 0;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
