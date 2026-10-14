<?php

namespace App\Support\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Exceptions\Fellowship\FellowshipImportException;

/**
 * The admin's decision about every header in an uploaded workbook.
 *
 * Column names are never hardcoded. Whatever the file happens to call its
 * columns, the admin maps each one onto a system field, an existing dynamic
 * filter, a new dynamic filter, or nothing at all — and the Fellowship itself is
 * never among the choices, because it comes from the record the wizard was
 * opened on.
 *
 * The map is stored with the import rather than applied on the spot, because
 * confirmation needs to know which filter columns this particular file covered:
 * an update must clear a filter the file mapped but left blank, and leave alone
 * one the file never mentioned at all.
 *
 * `isComplete()` is what gates the Confirm button: a missing required mapping
 * blocks the import rather than importing something half-understood.
 *
 * @phpstan-type Entry array{target: string, filter_id?: int|null, filter_name?: string|null}
 */
final readonly class ImportHeaderMap
{
    /** Answer options need two mapped columns, not merely one. */
    public const MINIMUM_OPTION_COLUMNS = 2;

    /**
     * @param  array<string, array{target: string, filter_id?: int|null, filter_name?: string|null}>  $entries  Keyed by header, in the file's own column order.
     */
    private function __construct(public array $entries) {}

    /**
     * @param  array<string, array<string, mixed>|string|null>  $entries
     */
    public static function fromArray(array $entries): self
    {
        $normalized = [];

        foreach ($entries as $header => $entry) {
            $header = (string) $header;

            if (is_string($entry)) {
                $entry = ['target' => $entry];
            }

            if (! is_array($entry)) {
                continue;
            }

            $target = FellowshipImportMappingTarget::tryFrom((string) ($entry['target'] ?? ''))
                ?? FellowshipImportMappingTarget::Ignore;

            $normalized[$header] = [
                'target' => $target->value,
                'filter_id' => isset($entry['filter_id']) && $entry['filter_id'] !== null && $entry['filter_id'] !== ''
                    ? (int) $entry['filter_id']
                    : null,
                'filter_name' => isset($entry['filter_name']) && trim((string) $entry['filter_name']) !== ''
                    ? trim((string) $entry['filter_name'])
                    : null,
            ];
        }

        return new self($normalized);
    }

    /**
     * @return array<string, array{target: string, filter_id?: int|null, filter_name?: string|null}>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * Headers mapped to one target, in the file's column order — which is the
     * order answer options are read in.
     *
     * @return list<string>
     */
    public function headersFor(FellowshipImportMappingTarget $target): array
    {
        $headers = [];

        foreach ($this->entries as $header => $entry) {
            if ($entry['target'] === $target->value) {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    public function headerFor(FellowshipImportMappingTarget $target): ?string
    {
        return $this->headersFor($target)[0] ?? null;
    }

    public function has(FellowshipImportMappingTarget $target): bool
    {
        return $this->headersFor($target) !== [];
    }

    /**
     * Every header mapped to a dynamic filter, with the filter it feeds.
     *
     * A `filter_id` means an existing filter, reused; a `filter_name` alone
     * means one to be created when the import is confirmed. Both are returned
     * here, because an update has to treat "mapped, and empty" the same way for
     * either kind.
     *
     * @return list<array{header: string, filter_id: int|null, filter_name: string|null, is_new: bool}>
     */
    public function dynamicFilterColumns(): array
    {
        $columns = [];

        foreach ($this->entries as $header => $entry) {
            $target = FellowshipImportMappingTarget::from($entry['target']);

            if (! $target->isDynamicFilter()) {
                continue;
            }

            $columns[] = [
                'header' => (string) $header,
                'filter_id' => $entry['filter_id'] ?? null,
                'filter_name' => $entry['filter_name'] ?? null,
                'is_new' => $target === FellowshipImportMappingTarget::NewFilter,
            ];
        }

        return $columns;
    }

    /**
     * Required targets with no column behind them. Non-empty means the import
     * cannot be confirmed.
     *
     * @return list<FellowshipImportMappingTarget>
     */
    public function missingRequiredTargets(): array
    {
        $missing = [];

        foreach (FellowshipImportMappingTarget::required() as $target) {
            if ($target === FellowshipImportMappingTarget::AnswerOption) {
                if (count($this->headersFor($target)) < self::MINIMUM_OPTION_COLUMNS) {
                    $missing[] = $target;
                }

                continue;
            }

            if (! $this->has($target)) {
                $missing[] = $target;
            }
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingRequiredTargets() === [];
    }

    /**
     * Reject a map that cannot be applied coherently, before anything is
     * written.
     *
     * Two columns feeding one filter would make "one value per filter per
     * question" unsatisfiable, and a new filter without a name has nothing to
     * be created as.
     */
    public function assertUsable(): void
    {
        if (! $this->isComplete()) {
            throw FellowshipImportException::incompleteMapping(array_map(
                static fn (FellowshipImportMappingTarget $target): string => $target->value,
                $this->missingRequiredTargets(),
            ));
        }

        $seen = [];

        foreach ($this->dynamicFilterColumns() as $column) {
            $identity = $column['filter_id'] !== null
                ? 'id:'.$column['filter_id']
                : 'name:'.QuestionTextNormalizer::normalize($column['filter_name'] ?? '');

            if ($column['filter_id'] === null && ($column['filter_name'] ?? '') === '') {
                throw FellowshipImportException::incompleteMapping(['new_filter_name']);
            }

            if (isset($seen[$identity])) {
                throw FellowshipImportException::duplicateFilterName(
                    $column['filter_name'] ?? ('#'.$column['filter_id'])
                );
            }

            $seen[$identity] = true;
        }
    }

    /**
     * Headers the admin has not decided about yet — neither mapped nor
     * explicitly ignored. Used to nudge the admin rather than to block them,
     * since an unmapped optional column is simply not imported.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    public function undecidedHeaders(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            fn (string $header): bool => ! array_key_exists($header, $this->entries),
        ));
    }
}
