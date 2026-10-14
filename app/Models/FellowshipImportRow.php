<?php

namespace App\Models;

use App\Enums\FellowshipImportDuplicateScope;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportRowStatus;
use App\Support\Fellowship\ImportRowPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spreadsheet row as staging understood it, and what became of it.
 *
 * `payload` is the mapped, normalized row. Confirmation works from it rather
 * than from the workbook, so the file does not have to survive the admin's
 * review, and a retried confirmation reads exactly what the admin approved.
 *
 * A duplicate row's `decision` has no default. Until the admin records one the
 * row awaits a decision and the whole import is blocked, because skipping,
 * updating and inserting a duplicate are all consequential and none of them
 * should happen merely because nobody chose.
 */
class FellowshipImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_import_id',
        'row_number',
        'status',
        'duplicate_scope',
        'duplicate_of_question_id',
        'duplicate_of_row_number',
        'decision',
        'errors',
        'payload',
        'outcome',
        'fellowship_question_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'status' => FellowshipImportRowStatus::class,
            'duplicate_scope' => FellowshipImportDuplicateScope::class,
            'duplicate_of_row_number' => 'integer',
            'decision' => FellowshipImportRowDecision::class,
            'errors' => 'array',
            'payload' => 'array',
            'outcome' => FellowshipImportRowOutcome::class,
            'processed_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(FellowshipImport::class, 'fellowship_import_id');
    }

    /**
     * The stored question this row duplicates, when it duplicates one. Null for
     * a row that duplicates an earlier row of the same file, and null again once
     * that question is force-deleted.
     */
    public function duplicateOfQuestion(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuestion::class, 'duplicate_of_question_id');
    }

    /**
     * The question this row produced, once confirmation has run.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuestion::class, 'fellowship_question_id');
    }

    public function payload(): ImportRowPayload
    {
        return ImportRowPayload::fromArray($this->payload ?? []);
    }

    public function isValid(): bool
    {
        return $this->status === FellowshipImportRowStatus::Valid;
    }

    public function isInvalid(): bool
    {
        return $this->status === FellowshipImportRowStatus::Invalid;
    }

    public function isDuplicate(): bool
    {
        return $this->status === FellowshipImportRowStatus::Duplicate;
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Updating an existing question is only on offer when a stored question can
     * still be identified — never for a row duplicating an earlier row of the
     * same upload, which has not been inserted yet, and not once the question it
     * matched has been force-deleted.
     *
     * @return list<FellowshipImportRowDecision>
     */
    public function availableDecisions(): array
    {
        if (! $this->isDuplicate()) {
            return [];
        }

        if ($this->canUpdateExisting()) {
            return [
                FellowshipImportRowDecision::Skip,
                FellowshipImportRowDecision::UpdateExisting,
                FellowshipImportRowDecision::KeepBoth,
            ];
        }

        return [
            FellowshipImportRowDecision::Skip,
            FellowshipImportRowDecision::KeepBoth,
        ];
    }

    /**
     * Whether a stored question for this row can actually be identified.
     *
     * Reads the loaded relation when the caller has eager-loaded it, so a
     * review screen rendering many rows does not issue a query per row.
     */
    public function canUpdateExisting(): bool
    {
        if ($this->duplicate_scope !== FellowshipImportDuplicateScope::Existing) {
            return false;
        }

        if ($this->duplicate_of_question_id === null) {
            return false;
        }

        if ($this->relationLoaded('duplicateOfQuestion')) {
            return $this->getRelation('duplicateOfQuestion') !== null;
        }

        return $this->duplicateOfQuestion()->exists();
    }

    public function isDecided(): bool
    {
        return $this->decision !== null;
    }

    /**
     * A duplicate still awaiting the admin's decision.
     *
     * There is deliberately no default: skipping, updating and inserting are
     * all consequential, so none of them may happen because nobody chose.
     * Confirmation is blocked while any row answers true here.
     */
    public function awaitsDecision(): bool
    {
        return $this->isDuplicate() && ! $this->isProcessed() && ! $this->isDecided();
    }

    /**
     * Whether this row may be *offered* this decision. Used by the review
     * screen, so an impossible choice never appears in the first place.
     */
    public function allows(FellowshipImportRowDecision $decision): bool
    {
        return in_array($decision, $this->availableDecisions(), true);
    }

    /**
     * Whether this decision is structurally possible for this row, ignoring
     * whether the matched question still exists.
     *
     * Confirmation validates against this rather than {@see allows()} so that a
     * question force-deleted between review and confirmation is reported as
     * exactly that, instead of as a decision the admin was never offered.
     */
    public function allowsByScope(FellowshipImportRowDecision $decision): bool
    {
        if (! $this->isDuplicate()) {
            return false;
        }

        if ($decision !== FellowshipImportRowDecision::UpdateExisting) {
            return true;
        }

        return $this->duplicate_scope === FellowshipImportDuplicateScope::Existing;
    }

    /**
     * @param  Builder<FellowshipImportRow>  $query
     * @return Builder<FellowshipImportRow>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    /**
     * Duplicates with no decision recorded, which are what block confirmation.
     *
     * @param  Builder<FellowshipImportRow>  $query
     * @return Builder<FellowshipImportRow>
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query
            ->where('status', FellowshipImportRowStatus::Duplicate)
            ->whereNull('decision')
            ->whereNull('processed_at');
    }

    /**
     * Rows confirmation may act on: the invalid ones are reported and excluded
     * without holding up the rest of the file.
     *
     * @param  Builder<FellowshipImportRow>  $query
     * @return Builder<FellowshipImportRow>
     */
    public function scopeImportable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FellowshipImportRowStatus::Valid->value,
            FellowshipImportRowStatus::Duplicate->value,
        ]);
    }
}
