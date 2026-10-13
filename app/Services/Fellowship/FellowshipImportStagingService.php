<?php

namespace App\Services\Fellowship;

use App\Contracts\Fellowship\SpreadsheetReader;
use App\Enums\FellowshipImportDuplicateScope;
use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\ImportFilePath;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turning an uploaded workbook plus a header map into a reviewable import.
 *
 * Staging is read-only as far as the question bank is concerned. It validates
 * every row, works out which rows duplicate each other or something already
 * stored, and writes all of that to `fellowship_import_rows` — but it creates no
 * question, no filter and no filter value. An import previewed and then
 * abandoned therefore leaves the Fellowship exactly as it was, which is why the
 * admin can map, look, re-map and look again without consequence.
 *
 * Rows are judged one at a time. An invalid row is recorded with its reasons and
 * excluded; it never holds up the valid rows around it.
 */
class FellowshipImportStagingService
{
    public function __construct(
        private SpreadsheetReader $reader,
        private FellowshipImportValidator $validator,
    ) {}

    /**
     * The file's column names, for the mapping step to offer.
     *
     * @return list<string>
     */
    public function headers(string $path): array
    {
        return $this->reader->headers($path);
    }

    /**
     * A sample of rows keyed by header, so the mapping step can show the admin
     * what is actually in each column.
     *
     * @return list<array<string, string|null>>
     */
    public function preview(string $path, int $limit = 5): array
    {
        return array_slice($this->reader->rows($path), 0, max(1, $limit));
    }

    /**
     * Stage the import: validate, duplicate-match, and store a row per
     * spreadsheet row.
     *
     * Re-staging the same import replaces its rows, so an admin who goes back
     * and changes the mapping reviews the new reading rather than a mixture of
     * both. Nothing in the Fellowship itself is written.
     */
    public function stage(FellowshipImport $import, ImportHeaderMap $map): FellowshipImport
    {
        $map->assertUsable();

        /** @var Fellowship $fellowship */
        $fellowship = $import->fellowship()->firstOrFail();

        $path = $this->resolvePath($import);
        $headers = $this->reader->headers($path);
        $rows = $this->reader->rows($path);

        if ($rows === []) {
            throw FellowshipImportException::noDataRows();
        }

        $maxRows = (int) config('fellowship.import.max_rows', 5000);

        if (count($rows) > $maxRows) {
            throw FellowshipImportException::tooManyRows($maxRows);
        }

        $existingHashes = $this->existingHashes($fellowship);
        $seenInFile = [];

        $staged = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0];

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['__row_number'] ?? 0);
            $payload = $this->payloadFor($row, $map, $headers);
            $errors = $this->validator->validate($payload, $map);

            if ($errors !== []) {
                $counts['invalid']++;

                $staged[] = [
                    'row_number' => $rowNumber,
                    'status' => FellowshipImportRowStatus::Invalid->value,
                    'errors' => $errors,
                    'payload' => $payload->toArray(),
                ];

                continue;
            }

            $hash = $payload->contentHash();

            // Earlier rows of this same upload are checked first: a file
            // repeating a question twice is the admin's more immediate problem,
            // and the second row has no stored question to update yet.
            if (isset($seenInFile[$hash])) {
                $counts['duplicate']++;

                $staged[] = [
                    'row_number' => $rowNumber,
                    'status' => FellowshipImportRowStatus::Duplicate->value,
                    'duplicate_scope' => FellowshipImportDuplicateScope::File->value,
                    'duplicate_of_row_number' => $seenInFile[$hash],
                    'payload' => $payload->toArray(),
                ];

                continue;
            }

            $seenInFile[$hash] = $rowNumber;

            if (isset($existingHashes[$hash])) {
                $counts['duplicate']++;

                $staged[] = [
                    'row_number' => $rowNumber,
                    'status' => FellowshipImportRowStatus::Duplicate->value,
                    'duplicate_scope' => FellowshipImportDuplicateScope::Existing->value,
                    'duplicate_of_question_id' => $existingHashes[$hash],
                    'payload' => $payload->toArray(),
                ];

                continue;
            }

            $counts['valid']++;

            $staged[] = [
                'row_number' => $rowNumber,
                'status' => FellowshipImportRowStatus::Valid->value,
                'payload' => $payload->toArray(),
            ];
        }

        return DB::transaction(function () use ($import, $map, $headers, $staged, $counts): FellowshipImport {
            $import->rows()->delete();

            foreach (array_chunk($staged, 200) as $chunk) {
                foreach ($chunk as $row) {
                    $import->rows()->create($row);
                }
            }

            $import->update([
                'status' => FellowshipImportStatus::Staged,
                'headers' => $headers,
                'header_map' => $map->toArray(),
                'total_rows' => count($staged),
                'valid_rows' => $counts['valid'],
                'invalid_rows' => $counts['invalid'],
                'duplicate_rows' => $counts['duplicate'],
                'created_questions' => 0,
                'updated_questions' => 0,
                'skipped_questions' => 0,
                'failure_reason' => null,
                'confirmed_at' => null,
            ]);

            return $import->refresh();
        });
    }

    /**
     * Map one spreadsheet row onto the system's fields.
     *
     * Every cell holds a single value for its header; there is no multi-value
     * parsing anywhere here. Each mapped dynamic filter column contributes an
     * entry even when its cell is blank, because confirmation must be able to
     * tell "mapped and empty", which clears a value, from "absent from the
     * file", which preserves one.
     *
     * @param  array<string, string|null>  $row
     * @param  list<string>  $headers
     */
    private function payloadFor(array $row, ImportHeaderMap $map, array $headers): ImportRowPayload
    {
        $cell = static fn (?string $header): ?string => $header !== null && isset($row[$header]) && trim((string) $row[$header]) !== ''
            ? trim((string) $row[$header])
            : null;

        $options = [];

        foreach ($map->headersFor(FellowshipImportMappingTarget::AnswerOption) as $header) {
            $options[] = (string) ($cell($header) ?? '');
        }

        $filters = [];

        foreach ($map->dynamicFilterColumns() as $column) {
            $filters[] = [
                'filter_id' => $column['filter_id'],
                'filter_name' => (string) ($column['filter_name'] ?? $column['header']),
                'value' => $cell($column['header']),
            ];
        }

        $raw = [];

        foreach ($headers as $header) {
            $raw[$header] = $cell($header);
        }

        return ImportRowPayload::make(
            questionText: (string) ($cell($map->headerFor(FellowshipImportMappingTarget::QuestionText)) ?? ''),
            explanation: $cell($map->headerFor(FellowshipImportMappingTarget::Explanation)),
            difficulty: $cell($map->headerFor(FellowshipImportMappingTarget::Difficulty)),
            correctAnswer: $cell($map->headerFor(FellowshipImportMappingTarget::CorrectAnswer)),
            performanceTopic: $cell($map->headerFor(FellowshipImportMappingTarget::PerformanceTopic)),
            options: $options,
            filters: $filters,
            raw: $raw,
        );
    }

    /**
     * The Fellowship's stored questions by content hash, for duplicate
     * matching. Soft-deleted questions are excluded: a deleted question is not
     * something an import should be asked to update.
     *
     * @return array<string, int>
     */
    private function existingHashes(Fellowship $fellowship): array
    {
        return FellowshipQuestion::query()
            ->forFellowship($fellowship->getKey())
            ->orderBy('id')
            ->pluck('id', 'content_hash')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The absolute path of the import's workbook.
     *
     * The stored path is re-checked here and not taken on trust. It reaches the
     * record from Livewire state, and `path()` does no normalising of its own,
     * so a path that was never one of this module's uploads would be opened —
     * and staged, cell by cell, into rows the admin can then read. Checking it
     * at the point of use means that holds however the record was written.
     */
    private function resolvePath(FellowshipImport $import): string
    {
        $path = ImportFilePath::assert($import->stored_path);

        $disk = (string) config('fellowship.import.disk', 'local');
        $absolute = Storage::disk($disk)->path($path);

        if (! is_file($absolute)) {
            throw FellowshipImportException::unreadableFile('The upload is no longer available.');
        }

        return $absolute;
    }
}
