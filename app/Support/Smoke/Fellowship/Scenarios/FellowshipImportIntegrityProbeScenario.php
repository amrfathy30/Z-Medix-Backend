<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\CreateFellowship;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Support\Smoke\Fellowship\FellowshipImportWizardDriver;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Two claims about the import that only a real MySQL run can settle, driven
 * through the real wizard.
 *
 * Both are covered by the SQLite suite already, and in both cases what the
 * SQLite suite cannot show is the part that matters:
 *
 *  - **Clearing an optional filter value.** The suite proves the pivot row goes
 *    and that an absent column is left alone. What it cannot prove is that the
 *    delete reaches MySQL as a delete — the question's value is a row in a pivot
 *    carrying a unique index and two foreign keys, and `updateOrCreate` against
 *    that index is the operation whose behaviour differs between engines. This
 *    checks the pivot, the filter, and the filter's vocabulary separately, since
 *    clearing one question's value must not disturb the value every other
 *    question is filed under.
 *
 *  - **A failure during confirmation.** The suite stands in for an interrupted
 *    run by stamping a row by hand and confirming again, which proves resumption
 *    but never exercises the recovery: the `catch` that puts the import back to
 *    `staged` with its reason, so a retry can claim it. That path has only ever
 *    run in anger — it is what the ONLY_FULL_GROUP_BY tally failure hit, and it
 *    left the import stranded in `processing` where no later claim could reach
 *    it. So here a genuine exception is injected at the one point the module
 *    cannot catch per row, after every row has been committed, and the recovery
 *    and the retry are both checked against MySQL.
 *
 * Nothing existing is touched. The probe works in a Fellowship of its own,
 * named with {@see FellowshipSmokeFixtures::MARKER} so the main run's cleanup
 * sweeps it too, and it removes its own previous records before it starts. It
 * never migrates, seeds or truncates.
 */
class FellowshipImportIntegrityProbeScenario
{
    public const FELLOWSHIP_NAME = FellowshipSmokeFixtures::MARKER.'Import Integrity Probe';

    /** Where this probe's workbooks are written, relative to the storage path. */
    public const DIRECTORY = 'smoke/fellowship-integrity';

    /** Question rows in the first import, every one fully classified. */
    private const INITIAL_ROWS = 4;

    /** New question rows in the import whose confirmation is made to fail. */
    private const FAILURE_ROWS = 5;

    public function __construct(
        private FellowshipImportWizardDriver $wizard,
        private FellowshipSmokeFixtures $fixtures,
    ) {}

    public function run(FellowshipSmokeContext $context): void
    {
        $admin = $context->admin;

        if ($admin === null) {
            $context->ledger->skip('Import integrity probe', 'No admin account is available to import as.');

            return;
        }

        $this->wizard->actingAs($admin);

        $fellowship = $this->prepareFellowship($context);

        if ($fellowship === null) {
            return;
        }

        $context->fellowship = $fellowship;

        $filters = $this->importInitialQuestions($context, $fellowship);

        if ($filters === null) {
            return;
        }

        $this->updateClearsAndPreserves($context, $fellowship, $filters);
        $this->injectedFailureRecoversAndRetries($context, $fellowship);
    }

    // ── The probe's own Fellowship ────────────────────────────────────────────

    /**
     * Remove whatever a previous probe left, then create the Fellowship through
     * the admin panel's own Create page.
     */
    private function prepareFellowship(FellowshipSmokeContext $context): ?Fellowship
    {
        $ledger = $context->ledger;
        $ledger->group('Probe · Setup');

        $removed = $this->cleanPreviousProbe();

        $ledger->pass(sprintf(
            'Previous probe records removed (fellowships: %d, questions: %d)',
            $removed['fellowships'],
            $removed['questions'],
        ));

        // Taken after the cleanup, not before it: a baseline that still counted
        // the last probe's own Fellowship would report its removal as this run
        // having changed rows it does not own.
        $context->baselineForeignCounts = $this->fixtures->foreignRowCounts([], []);

        $ledger->attempt('Probe Fellowship created through the Create page', function (): void {
            Livewire::test(CreateFellowship::class)
                ->set('data.name', self::FELLOWSHIP_NAME)
                ->set('data.is_active', true)
                ->call('create')
                ->assertHasNoFormErrors();
        });

        /** @var Fellowship|null $fellowship */
        $fellowship = Fellowship::query()->where('name', self::FELLOWSHIP_NAME)->first();

        if (! $ledger->check(
            'Probe Fellowship exists in MySQL',
            $fellowship !== null,
            'No fellowships row was written for '.self::FELLOWSHIP_NAME,
        )) {
            return null;
        }

        $ledger->note('Probe Fellowship ID', $fellowship->getKey());

        return $fellowship;
    }

    /**
     * Remove this probe's own records, and nothing else.
     *
     * Questions go before their Fellowship: a question's Performance Topic is a
     * RESTRICT reference to a filter value, and those values go the moment the
     * Fellowship's filters cascade.
     *
     * @return array<string, int>
     */
    private function cleanPreviousProbe(): array
    {
        return DB::transaction(function (): array {
            $removed = ['fellowships' => 0, 'questions' => 0];

            $fellowships = Fellowship::withTrashed()
                ->where('name', self::FELLOWSHIP_NAME)
                ->get();

            foreach ($fellowships as $fellowship) {
                $fellowship->update(['performance_filter_id' => null]);

                $removed['questions'] += FellowshipQuestion::withTrashed()
                    ->where('fellowship_id', $fellowship->getKey())
                    ->forceDelete();

                FellowshipFilter::query()
                    ->where('fellowship_id', $fellowship->getKey())
                    ->delete();

                $fellowship->forceDelete();
                $removed['fellowships']++;
            }

            return $removed;
        });
    }

    // ── Import one: a fully classified question bank ──────────────────────────

    /**
     * Import questions carrying a value for each of two optional filters, so the
     * update that follows has something real to clear and something real to
     * leave alone.
     *
     * @return array{subspecialty: FellowshipFilter, modality: FellowshipFilter}|null
     */
    private function importInitialQuestions(FellowshipSmokeContext $context, Fellowship $fellowship): ?array
    {
        $ledger = $context->ledger;
        $ledger->group('Probe · Initial import');

        $path = $this->writeWorkbook(
            'P1_initial.xlsx',
            ['Question Text', 'Option A', 'Option B', 'Correct Answer', 'Difficulty', 'System', 'Subspecialty', 'Modality'],
            array_map(fn (int $index): array => [
                $this->questionText($index),
                'Option A '.$index,
                'Option B '.$index,
                'A',
                $index % 2 === 0 ? 'Easy' : 'Hard',
                'Cardiology',
                'Interventional',
                'Angiography',
            ], range(1, self::INITIAL_ROWS)),
        );

        $component = $ledger->attempt(
            'Initial workbook uploaded and read',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $path),
        );

        if ($component === null) {
            return null;
        }

        $this->wizard->map($component, [
            'question_text' => 'Question Text',
            'option_1' => 'Option A',
            'option_2' => 'Option B',
            'correct_answer' => 'Correct Answer',
            'difficulty' => 'Difficulty',
            'performance_topic' => 'System',
        ], [
            'Subspecialty' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Subspecialty'],
            'Modality' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Modality'],
        ]);

        $this->wizard->stage($component);
        $this->wizard->confirm($component);

        $import = $fellowship->imports()->orderByDesc('id')->first();

        $ledger->same(
            'Initial import completed',
            FellowshipImportStatus::Completed,
            $import?->status,
        );
        $ledger->same('Initial import created every row', self::INITIAL_ROWS, $import?->created_questions);
        $ledger->same('Question bank holds the imported rows', self::INITIAL_ROWS, $fellowship->questions()->count());

        $subspecialty = $this->filterNamed($fellowship, 'Subspecialty');
        $modality = $this->filterNamed($fellowship, 'Modality');

        if (! $ledger->check(
            'Both optional filters were created by the import',
            $subspecialty !== null && $modality !== null,
            'Subspecialty: '.var_export($subspecialty !== null, true).', Modality: '.var_export($modality !== null, true),
        )) {
            return null;
        }

        // The pivot, counted directly, because that is the table the update is
        // about to delete from.
        $ledger->same(
            'Every question holds a Subspecialty value',
            self::INITIAL_ROWS,
            $this->pivotCount($fellowship, $subspecialty),
        );
        $ledger->same(
            'Every question holds a Modality value',
            self::INITIAL_ROWS,
            $this->pivotCount($fellowship, $modality),
        );

        return ['subspecialty' => $subspecialty, 'modality' => $modality];
    }

    // ── Claim one: mapped-and-empty clears, absent preserves ─────────────────

    /**
     * Re-import the same questions as updates, with Subspecialty mapped and
     * blank and Modality not in the file at all.
     *
     * @param  array{subspecialty: FellowshipFilter, modality: FellowshipFilter}  $filters
     */
    private function updateClearsAndPreserves(
        FellowshipSmokeContext $context,
        Fellowship $fellowship,
        array $filters,
    ): void {
        $ledger = $context->ledger;
        $ledger->group('Probe · Update clears an empty mapped filter');

        $subspecialty = $filters['subspecialty'];
        $modality = $filters['modality'];

        $valuesBefore = $subspecialty->values()->count();

        // Modality is absent from this file's header row entirely; Subspecialty
        // is present and every one of its cells is empty.
        $path = $this->writeWorkbook(
            'P2_update_clears.xlsx',
            ['Question Text', 'Option A', 'Option B', 'Correct Answer', 'Difficulty', 'System', 'Subspecialty'],
            array_map(fn (int $index): array => [
                $this->questionText($index),
                'Option A '.$index,
                'Option B '.$index,
                'A',
                $index % 2 === 0 ? 'Easy' : 'Hard',
                'Cardiology',
                '',
            ], range(1, self::INITIAL_ROWS)),
        );

        $component = $ledger->attempt(
            'Update workbook uploaded and read',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $path),
        );

        if ($component === null) {
            return;
        }

        $ledger->check(
            'The update workbook does not mention Modality at all',
            ! in_array('Modality', $this->wizard->headers($component), true),
            'Modality is in the header row, so this would not test an absent column.',
        );

        $this->wizard->map($component, [
            'question_text' => 'Question Text',
            'option_1' => 'Option A',
            'option_2' => 'Option B',
            'correct_answer' => 'Correct Answer',
            'difficulty' => 'Difficulty',
            'performance_topic' => 'System',
        ], [
            'Subspecialty' => [
                'target' => FellowshipImportMappingTarget::ExistingFilter->value,
                'filter_id' => $subspecialty->getKey(),
            ],
        ]);

        $this->wizard->stage($component);

        $import = $fellowship->imports()->orderByDesc('id')->first();

        if ($import === null) {
            $ledger->fail('Update import staged', 'No import row was written.');

            return;
        }

        $ledger->same('Every row of the update is a duplicate', self::INITIAL_ROWS, $import->duplicate_rows);
        $ledger->same(
            'Every duplicate starts awaiting a decision',
            self::INITIAL_ROWS,
            $import->unresolvedDuplicatesCount(),
        );

        $this->decideAll($component, $import, FellowshipImportRowDecision::UpdateExisting);

        $ledger->same(
            'No duplicate is left undecided before confirming',
            0,
            $import->refresh()->unresolvedDuplicatesCount(),
        );

        $this->wizard->confirm($component);

        $import->refresh();

        $ledger->same('Update import completed', FellowshipImportStatus::Completed, $import->status);
        $ledger->same('Every row was applied as an update', self::INITIAL_ROWS, $import->updated_questions);
        $ledger->same('The update inserted nothing', 0, $import->created_questions);
        $ledger->same(
            'The question bank did not grow',
            self::INITIAL_ROWS,
            $fellowship->questions()->count(),
        );

        // The claim itself, read off the pivot table in MySQL.
        $ledger->same(
            'A mapped, empty cell cleared the question\'s Subspecialty value',
            0,
            $this->pivotCount($fellowship, $subspecialty),
        );
        $ledger->same(
            'A column absent from the file left Modality exactly as it was',
            self::INITIAL_ROWS,
            $this->pivotCount($fellowship, $modality),
        );

        // Clearing one question's value must not touch the filter or the
        // vocabulary every other question is filed under.
        $ledger->check(
            'The Subspecialty filter itself survives the clearing',
            FellowshipFilter::query()->whereKey($subspecialty->getKey())->exists(),
            'The filter row was removed along with the questions\' values.',
        );
        $ledger->same(
            'The Subspecialty values survive the clearing',
            $valuesBefore,
            $subspecialty->refresh()->values()->count(),
        );

        // Nothing orphaned: no pivot row left pointing at a value of a filter
        // the question no longer carries.
        $ledger->same(
            'No orphaned pivot rows were left behind',
            0,
            (int) DB::table('fellowship_question_filter_values as pivot')
                ->leftJoin('fellowship_filter_values as values', 'values.id', '=', 'pivot.fellowship_filter_value_id')
                ->join('fellowship_questions as questions', 'questions.id', '=', 'pivot.fellowship_question_id')
                ->where('questions.fellowship_id', $fellowship->getKey())
                ->where(function ($query): void {
                    $query->whereNull('values.id')
                        ->orWhereColumn('values.fellowship_filter_id', '!=', 'pivot.fellowship_filter_id');
                })
                ->count(),
        );
    }

    // ── Claim two: a genuine failure, recovery, and a safe retry ─────────────

    /**
     * Stage a fresh import, let every row commit, then make the step that
     * finalises the import fail for real.
     *
     * The injection point is deliberate. A row that fails is caught and recorded
     * against that row by design, so throwing from the writer would prove
     * nothing about the import's own recovery. What has never been exercised is
     * a failure *after* the rows are in — which is exactly where the tally
     * failure struck — so the exception is raised on the transition to
     * `completed`, once, and the module is left to recover from it.
     */
    private function injectedFailureRecoversAndRetries(FellowshipSmokeContext $context, Fellowship $fellowship): void
    {
        $ledger = $context->ledger;
        $ledger->group('Probe · Injected confirmation failure');

        $path = $this->writeWorkbook(
            'P3_failure.xlsx',
            ['Question Text', 'Option A', 'Option B', 'Correct Answer', 'Difficulty', 'System'],
            array_map(fn (int $index): array => [
                $this->questionText($index, 'Failure probe'),
                'Option A '.$index,
                'Option B '.$index,
                'A',
                'Medium',
                'Nephrology',
            ], range(1, self::FAILURE_ROWS)),
        );

        $component = $ledger->attempt(
            'Failure-probe workbook uploaded and read',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $path),
        );

        if ($component === null) {
            return;
        }

        $this->wizard->map($component, [
            'question_text' => 'Question Text',
            'option_1' => 'Option A',
            'option_2' => 'Option B',
            'correct_answer' => 'Correct Answer',
            'difficulty' => 'Difficulty',
            'performance_topic' => 'System',
        ]);

        $this->wizard->stage($component);

        $import = $fellowship->imports()->orderByDesc('id')->first();

        if ($import === null) {
            $ledger->fail('Failure-probe import staged', 'No import row was written.');

            return;
        }

        $ledger->same('Failure-probe import is staged', FellowshipImportStatus::Staged, $import->status);
        $ledger->same('Every failure-probe row is valid', self::FAILURE_ROWS, $import->valid_rows);

        $questionsBefore = $fellowship->questions()->count();
        $pivotBefore = (int) DB::table('fellowship_question_filter_values as pivot')
            ->join('fellowship_questions as questions', 'questions.id', '=', 'pivot.fellowship_question_id')
            ->where('questions.fellowship_id', $fellowship->getKey())
            ->count();

        $message = 'FELLOWSHIP_SMOKE_injected confirmation failure';
        $fired = false;

        // Fires once, on the one transition the module cannot catch per row.
        // Consumed on the first throw so the recovery's own write — which sets
        // the status back to `staged` — is free to succeed.
        FellowshipImport::updating(function (FellowshipImport $updating) use (&$fired, $message, $import): void {
            if ($fired || $updating->getKey() !== $import->getKey()) {
                return;
            }

            if ($updating->status !== FellowshipImportStatus::Completed) {
                return;
            }

            $fired = true;

            throw new RuntimeException($message);
        });

        $thrown = null;

        try {
            app(FellowshipImportConfirmationService::class)->confirm($import);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $ledger->check(
            'The injected failure actually fired',
            $fired,
            'The confirmation finished without reaching the injected failure, so nothing was tested.',
        );

        $ledger->check(
            'Confirmation reported the failure to the admin',
            $thrown instanceof FellowshipImportException,
            'Expected a FellowshipImportException, got '.($thrown === null ? 'nothing' : $thrown::class),
        );

        $import->refresh();

        // The recovery. `processing` here would be the stranding bug: no later
        // claim can pick an import out of it.
        $ledger->same(
            'The failed import is back to staged, where a retry can claim it',
            FellowshipImportStatus::Staged,
            $import->status,
        );
        $ledger->check(
            'The failure reason was kept on the import',
            $import->failure_reason !== null && str_contains((string) $import->failure_reason, $message),
            'failure_reason is '.var_export($import->failure_reason, true),
        );
        $ledger->same('The failed import is not stamped as confirmed', null, $import->confirmed_at);

        // The rows did commit: each one is in its own transaction, so a failure
        // after them cannot roll them back.
        $ledger->same(
            'Every row was committed before the failure',
            self::FAILURE_ROWS,
            $import->processedRows()->count(),
        );
        $ledger->same(
            'Every committed row records what it did',
            self::FAILURE_ROWS,
            $import->rows()->where('outcome', FellowshipImportRowOutcome::Created)->count(),
        );
        $ledger->same(
            'No row was left pending',
            0,
            $import->rows()->importable()->pending()->count(),
        );
        $ledger->same(
            'Every committed row is linked to the question it created',
            self::FAILURE_ROWS,
            $import->rows()->whereNotNull('fellowship_question_id')->count(),
        );
        $ledger->same(
            'The questions the committed rows created are in MySQL',
            $questionsBefore + self::FAILURE_ROWS,
            $fellowship->questions()->count(),
        );

        // ── The retry ────────────────────────────────────────────────────────

        $retried = $ledger->attempt(
            'The failed import can be retried',
            fn () => app(FellowshipImportConfirmationService::class)->confirm($import->refresh()),
        );

        if ($retried === null) {
            return;
        }

        $import->refresh();

        $ledger->same('The retry completed the import', FellowshipImportStatus::Completed, $import->status);
        $ledger->check('The retry stamped the import as confirmed', $import->confirmed_at !== null, 'confirmed_at is null.');
        $ledger->same('The retry reports the rows it created', self::FAILURE_ROWS, $import->created_questions);

        $ledger->same(
            'The retry inserted no question twice',
            $questionsBefore + self::FAILURE_ROWS,
            $fellowship->questions()->count(),
        );
        $ledger->same(
            'The retry duplicated no filter value',
            $pivotBefore,
            (int) DB::table('fellowship_question_filter_values as pivot')
                ->join('fellowship_questions as questions', 'questions.id', '=', 'pivot.fellowship_question_id')
                ->where('questions.fellowship_id', $fellowship->getKey())
                ->count(),
        );

        // Said twice over, because "no duplicates" is the whole claim: no two
        // live questions in this Fellowship share a content hash.
        $ledger->same(
            'No two questions in the Fellowship share a content hash',
            0,
            (int) DB::table('fellowship_questions')
                ->where('fellowship_id', $fellowship->getKey())
                ->whereNull('deleted_at')
                ->select('content_hash')
                ->groupBy('content_hash')
                ->havingRaw('count(*) > 1')
                ->get()
                ->count(),
        );

        $ledger->same(
            'The retry left every row stamped exactly once',
            self::FAILURE_ROWS,
            $import->rows()->where('outcome', FellowshipImportRowOutcome::Created)->count(),
        );

        $ledger->note('Probe import IDs', implode(', ', $fellowship->imports()->pluck('id')->all()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Give every pending duplicate of an import the same decision, through the
     * review table's own select so the page's hook runs.
     */
    private function decideAll(Testable $component, FellowshipImport $import, FellowshipImportRowDecision $decision): void
    {
        $import->rowsAwaitingDecision()->get()->each(
            fn (FellowshipImportRow $row) => $this->wizard->decide($component, (int) $row->getKey(), $decision->value),
        );
    }

    private function filterNamed(Fellowship $fellowship, string $name): ?FellowshipFilter
    {
        /** @var FellowshipFilter|null $filter */
        $filter = $fellowship->filters()->where('name', $name)->first();

        return $filter;
    }

    /**
     * How many of this Fellowship's questions hold a value for one filter,
     * counted on the pivot itself rather than through a relation.
     */
    private function pivotCount(Fellowship $fellowship, FellowshipFilter $filter): int
    {
        return (int) DB::table('fellowship_question_filter_values as pivot')
            ->join('fellowship_questions as questions', 'questions.id', '=', 'pivot.fellowship_question_id')
            ->where('questions.fellowship_id', $fellowship->getKey())
            ->whereNull('questions.deleted_at')
            ->where('pivot.fellowship_filter_id', $filter->getKey())
            ->count();
    }

    private function questionText(int $index, string $prefix = 'Integrity probe'): string
    {
        return sprintf('%s question %d — which finding fits best?', $prefix, $index);
    }

    /**
     * Write one real .xlsx, every cell an explicit string so a value like "1"
     * comes back as text rather than a number.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function writeWorkbook(string $filename, array $headers, array $rows): string
    {
        $directory = storage_path('app/'.self::DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, recursive: true);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Questions');

        foreach ($headers as $column => $header) {
            $sheet->setCellValueExplicit([$column + 1, 1], $header, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $rowIndex + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        $path = $directory.'/'.$filename;

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
