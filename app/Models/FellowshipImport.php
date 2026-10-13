<?php

namespace App\Models;

use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipImportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the question import wizard, kept as history.
 *
 * The Fellowship is part of the record rather than part of the file: the import
 * is created from a Fellowship's admin pages and can only ever write into that
 * Fellowship.
 */
class FellowshipImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_id',
        'admin_id',
        'status',
        'original_filename',
        'stored_path',
        'headers',
        'header_map',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'created_questions',
        'updated_questions',
        'skipped_questions',
        'failure_reason',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FellowshipImportStatus::class,
            'headers' => 'array',
            'header_map' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'created_questions' => 'integer',
            'updated_questions' => 'integer',
            'skipped_questions' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function fellowship(): BelongsTo
    {
        return $this->belongsTo(Fellowship::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(FellowshipImportRow::class)->orderBy('row_number');
    }

    /**
     * The rows the admin still has to decide on.
     */
    public function duplicateRows(): HasMany
    {
        return $this->rows()->where('status', FellowshipImportRowStatus::Duplicate);
    }

    public function invalidRows(): HasMany
    {
        return $this->rows()->where('status', FellowshipImportRowStatus::Invalid);
    }

    /**
     * Duplicate rows with no decision recorded yet.
     *
     * These block confirmation outright: a duplicate is never skipped, updated
     * or inserted on the admin's behalf.
     */
    public function rowsAwaitingDecision(): HasMany
    {
        return $this->rows()->awaitingDecision();
    }

    public function unresolvedDuplicatesCount(): int
    {
        return $this->rowsAwaitingDecision()->count();
    }

    public function hasUnresolvedDuplicates(): bool
    {
        return $this->rowsAwaitingDecision()->exists();
    }

    public function isStaged(): bool
    {
        return $this->status === FellowshipImportStatus::Staged;
    }

    public function isCompleted(): bool
    {
        return $this->status === FellowshipImportStatus::Completed;
    }

    /**
     * Rows confirmation has already acted on. Re-running a confirmation skips
     * these, which is what keeps a retry from inserting anything twice.
     */
    public function processedRows(): HasMany
    {
        return $this->rows()->whereNotNull('processed_at');
    }

    /**
     * Recount the outcome tallies from the rows themselves, so the summary can
     * never drift from what actually happened.
     */
    public function refreshOutcomeCounts(): void
    {
        $outcomes = $this->rows()
            // `rows()` orders by row number, which an aggregate over outcomes
            // has no business carrying: MySQL under ONLY_FULL_GROUP_BY rejects
            // an ORDER BY on a column that is neither grouped nor aggregated.
            ->reorder()
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $this->update([
            'created_questions' => (int) $outcomes->get(FellowshipImportRowOutcome::Created->value, 0),
            'updated_questions' => (int) $outcomes->get(FellowshipImportRowOutcome::Updated->value, 0),
            'skipped_questions' => (int) $outcomes->get(FellowshipImportRowOutcome::Skipped->value, 0),
        ]);
    }

    /**
     * @param  Builder<FellowshipImport>  $query
     * @return Builder<FellowshipImport>
     */
    public function scopeStaged(Builder $query): Builder
    {
        return $query->where('status', FellowshipImportStatus::Staged);
    }
}
