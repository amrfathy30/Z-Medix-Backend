<?php

namespace App\Services\Fellowship;

use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applying a staged import: the one place questions, filters and filter values
 * are actually written.
 *
 * Retry safety is the design constraint. Two things guarantee it:
 *
 *  - the import's own status. Confirmation claims the import by moving it from
 *    `staged` to `processing` inside a locked read, so a double-clicked Confirm
 *    finds nothing to claim and does nothing;
 *  - each row's `processed_at`. A row already stamped is skipped, so a
 *    confirmation interrupted part way through can be run again and will finish
 *    the remaining rows rather than re-insert the finished ones.
 *
 * Rows are applied one per transaction. A single row that fails unexpectedly is
 * recorded as failed and the rest of the file still goes in — the same principle
 * as row validation, applied to the write.
 *
 * Duplicates are never resolved on the admin's behalf. Confirmation refuses to
 * start while any duplicate still awaits a decision, and that check lives here
 * rather than only on the review screen, so the rule holds however confirmation
 * was reached.
 */
class FellowshipImportConfirmationService
{
    public function __construct(private FellowshipQuestionWriter $writer) {}

    /**
     * Record the admin's decision for one duplicate row.
     *
     * Only the decisions that make sense for that row are accepted: updating an
     * existing question is not on offer for a row duplicating an earlier row of
     * the same file, which has nothing stored to update.
     */
    public function decide(FellowshipImportRow $row, FellowshipImportRowDecision $decision): FellowshipImportRow
    {
        if (! $row->isDuplicate()) {
            return $row;
        }

        if ($row->isProcessed()) {
            return $row;
        }

        if (! $row->allows($decision)) {
            throw FellowshipImportException::decisionNotAvailable();
        }

        $row->update(['decision' => $decision]);

        return $row->refresh();
    }

    /**
     * Apply one decision to a selection of rows — the review screen's bulk
     * action.
     *
     * Rows the decision does not suit are left alone rather than silently given
     * something else: a selection that includes a within-file duplicate cannot
     * be bulk-updated, so that row stays awaiting a decision and keeps blocking
     * confirmation until the admin deals with it. The count of rows actually
     * changed is returned so the caller can say what happened.
     *
     * @param  iterable<FellowshipImportRow>  $rows
     * @return array{applied: int, skipped: int}
     */
    public function decideMany(iterable $rows, FellowshipImportRowDecision $decision): array
    {
        $applied = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $decision, &$applied, &$skipped): void {
            foreach ($rows as $row) {
                if (! $row->isDuplicate() || $row->isProcessed() || ! $row->allows($decision)) {
                    $skipped++;

                    continue;
                }

                $row->update(['decision' => $decision]);
                $applied++;
            }
        });

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Clear a row's decision, putting it back to awaiting one.
     */
    public function undecide(FellowshipImportRow $row): FellowshipImportRow
    {
        if ($row->isDuplicate() && ! $row->isProcessed()) {
            $row->update(['decision' => null]);
        }

        return $row->refresh();
    }

    /**
     * Apply the import.
     *
     * Safe to call more than once: the second call finds the import already
     * claimed, or every row already stamped, and changes nothing.
     */
    public function confirm(FellowshipImport $import): FellowshipImport
    {
        // Re-checked here, not only on the review screen: the decisions are the
        // admin's instruction, and an import must never resolve a duplicate on
        // its own because the UI was bypassed, stale or scripted around.
        $this->assertDuplicatesResolved($import);

        $claimed = $this->claim($import);

        if (! $claimed) {
            // Either another request is applying it, or it is already done.
            // Both mean this call has nothing to do, which is exactly what makes
            // a retry harmless.
            return $import->refresh();
        }

        // The claim was written through a separately loaded row, so this
        // instance still believes it is staged. Without re-reading it, setting
        // the status back to `staged` below would not be a change Eloquent
        // writes, and a failed confirmation would leave the import stranded in
        // `processing` — which no later claim can pick up.
        $import->refresh();

        /** @var Fellowship $fellowship */
        $fellowship = $import->fellowship()->firstOrFail();
        $map = ImportHeaderMap::fromArray($import->header_map ?? []);
        $map->assertUsable();

        try {
            $import->rows()
                ->importable()
                ->pending()
                ->orderBy('row_number')
                ->get()
                ->each(fn (FellowshipImportRow $row) => $this->applyRow($fellowship, $import, $row, $map));

            $import->refreshOutcomeCounts();

            $import->update([
                'status' => FellowshipImportStatus::Completed,
                'confirmed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Whatever the completing write had already put on the instance is
            // not part of the recovery. `update()` fills before it saves, so a
            // failure raised from that save — a hook, a lock wait, a connection
            // lost mid-statement — leaves `confirmed_at` sitting dirty on this
            // model, and the recovery below would write it alongside the status
            // it is putting back: an import marked `staged` and stamped as
            // confirmed at the same time, which is a state no retry produces and
            // the history screen would read as a completed import.
            $import->discardChanges();

            // Put the import back where a retry can pick it up: the rows already
            // stamped stay stamped, so retrying resumes rather than repeats.
            $import->update([
                'status' => FellowshipImportStatus::Staged,
                'failure_reason' => $exception->getMessage(),
            ]);

            // Admin-facing, and not the database's words: the reason is kept on
            // the import for diagnosis, while what the admin is shown says the
            // import did not complete and can be retried.
            if (! $exception instanceof FellowshipImportException) {
                report($exception);

                throw FellowshipImportException::confirmationFailed();
            }

            throw $exception;
        }

        return $import->refresh();
    }

    /**
     * Refuse to confirm while any duplicate is still awaiting a decision.
     */
    public function assertDuplicatesResolved(FellowshipImport $import): void
    {
        $unresolved = $import->unresolvedDuplicatesCount();

        if ($unresolved > 0) {
            throw FellowshipImportException::undecidedDuplicates($unresolved);
        }
    }

    /**
     * Claim a staged import for this confirmation, under a row lock.
     *
     * Returns false when there is nothing to claim, which covers both a
     * concurrent confirmation and an import that has already completed.
     */
    private function claim(FellowshipImport $import): bool
    {
        return (bool) DB::transaction(function () use ($import): bool {
            /** @var FellowshipImport|null $locked */
            $locked = FellowshipImport::query()
                ->whereKey($import->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isStaged()) {
                return false;
            }

            $locked->update(['status' => FellowshipImportStatus::Processing]);

            return true;
        });
    }

    /**
     * Apply one row, then stamp it so a retry leaves it alone.
     */
    private function applyRow(
        Fellowship $fellowship,
        FellowshipImport $import,
        FellowshipImportRow $row,
        ImportHeaderMap $map,
    ): void {
        try {
            DB::transaction(function () use ($fellowship, $row, $map): void {
                // Re-read under a lock: if a concurrent confirmation stamped
                // this row between the outer query and here, leave it alone.
                /** @var FellowshipImportRow|null $locked */
                $locked = FellowshipImportRow::query()
                    ->whereKey($row->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->isProcessed()) {
                    return;
                }

                $payload = $locked->payload();

                if (! $locked->isDuplicate()) {
                    $question = $this->writer->create($fellowship, $payload, $map);

                    $this->stamp($locked, FellowshipImportRowOutcome::Created, $question);

                    return;
                }

                // No default: an undecided duplicate should have been stopped
                // by assertDuplicatesResolved(), so reaching here at all is a
                // fault worth recording rather than quietly resolving.
                $decision = $locked->decision;

                if ($decision === null) {
                    throw FellowshipImportException::undecidedDuplicates(1);
                }

                // Scope only: a question force-deleted since the review is
                // reported by applyUpdate() as precisely that.
                if (! $locked->allowsByScope($decision)) {
                    throw FellowshipImportException::decisionNotAvailable();
                }

                match ($decision) {
                    FellowshipImportRowDecision::Skip => $this->stamp($locked, FellowshipImportRowOutcome::Skipped),
                    FellowshipImportRowDecision::KeepBoth => $this->stamp(
                        $locked,
                        FellowshipImportRowOutcome::Created,
                        $this->writer->create($fellowship, $payload, $map),
                    ),
                    FellowshipImportRowDecision::UpdateExisting => $this->applyUpdate($fellowship, $locked, $map),
                };
            });
        } catch (FellowshipImportException $exception) {
            // A row the mapping cannot satisfy is this row's problem, not the
            // import's: record it and carry on with the rest of the file.
            $this->stamp($row, FellowshipImportRowOutcome::Failed, null, $exception->getMessage());
        } catch (Throwable $exception) {
            // The same principle, for a row that failed for a reason the module
            // does not model — a constraint it could not have foreseen, say. Its
            // own transaction has rolled back, so nothing of it was written, and
            // the rest of the file still goes in.
            //
            // The real exception is reported rather than shown: the admin gets a
            // sentence they can act on, not a fragment of SQL.
            report($exception);

            $this->stamp(
                $row,
                FellowshipImportRowOutcome::Failed,
                null,
                __('admin.fellowship.import_error_row_failed'),
            );
        }
    }

    /**
     * Apply a row over the existing question it duplicates.
     *
     * If that question has gone in the meantime, there is nothing to update:
     * the row is recorded as skipped rather than quietly becoming an insert the
     * admin did not ask for.
     */
    private function applyUpdate(Fellowship $fellowship, FellowshipImportRow $row, ImportHeaderMap $map): void
    {
        /** @var FellowshipQuestion|null $existing */
        $existing = $row->duplicate_of_question_id !== null
            ? FellowshipQuestion::query()->find($row->duplicate_of_question_id)
            : null;

        if ($existing === null) {
            // The admin asked for an update, so skipping would quietly do
            // something they did not choose. Recorded as failed, with the
            // reason, and nothing is inserted in its place.
            $this->stamp(
                $row,
                FellowshipImportRowOutcome::Failed,
                null,
                FellowshipImportException::updateTargetMissing()->getMessage(),
            );

            return;
        }

        $question = $this->writer->update($fellowship, $existing, $row->payload(), $map);

        $this->stamp($row, FellowshipImportRowOutcome::Updated, $question);
    }

    /**
     * @param  list<string>|string|null  $note
     */
    private function stamp(
        FellowshipImportRow $row,
        FellowshipImportRowOutcome $outcome,
        ?FellowshipQuestion $question = null,
        array|string|null $note = null,
    ): void {
        $attributes = [
            'outcome' => $outcome,
            'processed_at' => now(),
        ];

        if ($question !== null) {
            $attributes['fellowship_question_id'] = $question->getKey();
        }

        if ($note !== null) {
            $attributes['errors'] = array_values(array_merge($row->errors ?? [], (array) $note));
        }

        $row->update($attributes);
    }
}
