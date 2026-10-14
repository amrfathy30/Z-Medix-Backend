<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Enums\FellowshipImportDuplicateScope;
use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource;
use App\Filament\Resources\FellowshipResource\Pages\CreateFellowship;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Filament\Resources\FellowshipResource\Pages\ListFellowships;
use App\Models\Admin;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Support\Smoke\Fellowship\FellowshipImportWizardDriver;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use App\Support\Smoke\Fellowship\FellowshipSmokeWorkbooks;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The admin side, end to end, through the real Filament pages.
 *
 * The Fellowship is created on its own Create page, and every question in it
 * arrives through the import wizard — upload, map, stage, decide, confirm. No
 * question, filter or filter value is ever written directly, because the whole
 * claim being tested is that those paths produce the right records.
 *
 * Each workbook is here for a reason: A proves a clean first import, D the
 * mapping edge cases, B the duplicate review in all its decisions, and C that
 * bad rows are excluded with accurate reasons while the good rows beside them
 * still go in.
 */
class FellowshipAdminImportScenario
{
    public function __construct(private FellowshipImportWizardDriver $wizard) {}

    public function run(FellowshipSmokeContext $context): void
    {
        $admin = $context->admin;

        if ($admin === null) {
            $context->ledger->skip('Admin import flow', 'No admin account is available to import as.');

            return;
        }

        $this->wizard->actingAs($admin);

        $this->createFellowship($context);
        $this->refuseUnprivilegedAdmin($context);
        $this->refuseTooFewColumns($context);
        $this->refuseMissingUpload($context);

        $this->importWorkbookA($context);
        $this->mappingGuards($context);
        $this->importMappingEdgeCases($context);
        $this->importWorkbookB($context);
        $this->importWorkbookC($context);
        $this->confirmRetryIsSafe($context);
        $this->assertTranslationsAreComplete($context);

        $this->wizard->actingAs($admin);
    }

    // ── Step 1: the Fellowship ────────────────────────────────────────────────

    /**
     * Create the Fellowship through the admin panel's own Create page, and check
     * it starts out as an empty, unclassified bank.
     */
    private function createFellowship(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Fellowship creation');

        $name = FellowshipSmokeFixtures::FELLOWSHIP_NAME;

        $ledger->attempt('Fellowship created through the admin Create page', function () use ($name): void {
            Livewire::test(CreateFellowship::class)
                ->set('data.name', $name)
                ->set('data.is_active', true)
                ->call('create')
                ->assertHasNoFormErrors();
        });

        $fellowship = Fellowship::query()->where('name', $name)->first();

        if (! $ledger->check('Fellowship row exists in MySQL', $fellowship !== null, 'No fellowships row was written for '.$name)) {
            return;
        }

        $context->fellowship = $fellowship;
        $ledger->note('Smoke Fellowship ID', $fellowship->getKey());

        $ledger->same('Fellowship name stored', $name, $fellowship->name);
        $ledger->same('Fellowship is active', true, $fellowship->is_active);
        $ledger->same('Fellowship starts with no questions', 0, $fellowship->questions()->count());
        $ledger->same('Fellowship starts with no Performance Topic filter', null, $fellowship->performance_filter_id);
        $ledger->same('Fellowship starts with no filters at all', 0, $fellowship->filters()->count());

        // Visible on the resource's own list page, as the admin would find it.
        $ledger->attempt('Fellowship visible in the Filament list', function () use ($ledger, $fellowship): void {
            $visible = Livewire::test(ListFellowships::class)
                ->instance()
                ->getFilteredTableQuery()
                ->whereKey($fellowship->getKey())
                ->exists();

            $ledger->check('Fellowship visible in the Filament list', $visible, 'The new Fellowship is not in the list page query.');
        });

        // A second Fellowship, created the same way, so cross-Fellowship
        // isolation can be checked later against something real.
        $otherName = FellowshipSmokeFixtures::OTHER_FELLOWSHIP_NAME;

        $ledger->attempt('Second Fellowship created for isolation checks', function () use ($otherName): void {
            Livewire::test(CreateFellowship::class)
                ->set('data.name', $otherName)
                ->set('data.is_active', true)
                ->call('create');
        });

        $context->otherFellowship = Fellowship::query()->where('name', $otherName)->first();
        $ledger->check(
            'Second Fellowship exists',
            $context->otherFellowship !== null,
            'The isolation Fellowship was not created.',
        );
        $ledger->note('Isolation Fellowship ID', $context->otherFellowship?->getKey());
    }

    /**
     * An admin holding only the view permissions must be refused creation and
     * the import page, not merely have the buttons hidden.
     */
    private function refuseUnprivilegedAdmin(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Authorization');

        $viewer = app(FellowshipSmokeFixtures::class)->viewerAdmin();
        $fellowship = $context->fellowship;

        $ledger->same('Viewer admin may view Fellowships', true, $viewer->can('viewAny', Fellowship::class));
        $ledger->same('Viewer admin may not create a Fellowship', false, $viewer->can('create', Fellowship::class));

        if ($fellowship !== null) {
            $ledger->same('Viewer admin may not update a Fellowship', false, $viewer->can('update', $fellowship));
            $ledger->same('Viewer admin may not delete a Fellowship', false, $viewer->can('delete', $fellowship));
            $ledger->same(
                'Viewer admin may not create Fellowship questions',
                false,
                $viewer->can('create', FellowshipQuestion::class),
            );
        }

        // The import page's own gate. Importing writes questions, so it answers
        // to `fellowship_questions.create` — which the viewer does not hold.
        $this->wizard->actingAs($viewer);

        $ledger->same(
            'Import wizard is closed to an admin without question-create permission',
            false,
            ImportFellowshipQuestions::canAccess(['record' => $fellowship?->getKey()]),
        );

        $ledger->same(
            'Import wizard reports the viewer may not import',
            false,
            ImportFellowshipQuestions::canImport(),
        );

        // And the route enforces it, which is what actually protects the page:
        // a request for the import URL as the viewer must be refused outright.
        if ($fellowship !== null) {
            // Built by the resource, so the request goes to the panel's real
            // domain and path rather than one guessed here.
            $url = FellowshipResource::getUrl('import-questions', ['record' => $fellowship->getKey()], panel: 'admin');

            $ledger->same(
                'Requesting the import page as the viewer is refused with 403',
                403,
                $this->requestAsAdmin($viewer, $url),
            );
        }

        if ($context->admin !== null) {
            $this->wizard->actingAs($context->admin);

            $ledger->same(
                'Import wizard is open to the importing admin',
                true,
                ImportFellowshipQuestions::canAccess(['record' => $fellowship?->getKey()]),
            );
        }
    }

    /**
     * A file too narrow to hold a question is refused at upload rather than
     * carried into the mapping step.
     */
    private function refuseTooFewColumns(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Upload guards');

        $fellowship = $context->fellowship;
        $workbook = $context->workbooks['D5'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('Narrow workbook refused at upload', 'The Fellowship or workbook D5 is unavailable.');

            return;
        }

        $before = FellowshipImport::query()->where('fellowship_id', $fellowship->getKey())->count();

        $component = $ledger->attempt('Narrow workbook refused at upload', fn () => $this->wizard->uploadAndRead(
            $this->wizard->open($fellowship),
            $workbook['path'],
        ));

        if ($component === null) {
            return;
        }

        $ledger->same(
            'Narrow workbook leaves the wizard on the upload step',
            'upload',
            $component->get('step'),
        );

        $ledger->same(
            'Narrow workbook creates no import record',
            $before,
            FellowshipImport::query()->where('fellowship_id', $fellowship->getKey())->count(),
        );
    }

    /**
     * An upload path that is not there — a temporary upload swept between
     * requests — must fail on the field rather than further in.
     *
     * This is the one case that assigns a stored path directly, because the
     * recovery of a missing stored path is precisely what it targets.
     */
    private function refuseMissingUpload(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            $ledger->skip('Missing upload path fails safely', 'No Fellowship to upload into.');

            return;
        }

        $importsBefore = FellowshipImport::query()->where('fellowship_id', $fellowship->getKey())->count();

        $component = $ledger->attempt(
            'Missing upload path fails safely',
            fn () => $this->wizard->open($fellowship)
                // The one case that assigns a stored path on purpose: a
                // temporary upload swept between requests leaves exactly this
                // behind, and recovering from it is what is being checked.
                ->set('uploadData.file', [(string) Str::uuid() => 'fellowship-imports/this-file-was-swept.xlsx'])
                ->call('submitUpload'),
        );

        if ($component === null) {
            return;
        }

        // The page raises a validation error on the field itself; Livewire
        // collects it into the component's error bag rather than letting it
        // escape the action, so that bag is where it is read from.
        $messages = $component->errors()->get('uploadData.file');
        $message = implode(' ', $messages);

        $ledger->check(
            'Missing upload path raises an error on the upload field',
            $messages !== [],
            'No error was raised on uploadData.file. Errors: '.json_encode($component->errors()->toArray()),
        );

        // Compared against the translation rather than against English words:
        // the panel runs in whichever locale the admin has, and this run's
        // default is Arabic.
        $ledger->same(
            'The missing-upload message is the one the module defines',
            __('admin.fellowship.import_upload_missing'),
            $message,
        );

        $ledger->same(
            'A missing upload leaves the wizard on the upload step',
            'upload',
            $component->get('step'),
        );

        $ledger->same(
            'A missing upload creates no import record',
            $importsBefore,
            FellowshipImport::query()->where('fellowship_id', $fellowship->getKey())->count(),
        );
    }

    // ── Step 2: workbook A, the clean first import ────────────────────────────

    /**
     * Workbook A: 110 valid questions, correct answers written three different
     * ways, into an empty Fellowship.
     */
    private function importWorkbookA(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Import A (first import)');

        $fellowship = $context->fellowship;
        $workbook = $context->workbooks['A'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('Workbook A imported', 'The Fellowship or workbook A is unavailable.');

            return;
        }

        $component = $ledger->attempt(
            'Workbook A uploaded and headers read',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($component === null) {
            return;
        }

        $ledger->same('Workbook A reaches the mapping step', 'mapping', $component->get('step'));

        $importId = (int) $component->get('import');
        $import = FellowshipImport::query()->find($importId);

        if (! $ledger->check('Workbook A opened a draft import', $import !== null, 'No import record was created.')) {
            return;
        }

        $context->imports['A'] = $importId;
        $ledger->note('Import A ID', $importId);

        $ledger->same('Import A starts as a draft', FellowshipImportStatus::Draft, $import->status);
        $ledger->same('Import A preserves the original filename', basename($workbook['path']), $import->original_filename);
        $ledger->check(
            'Import A stored the upload on the configured import disk',
            $import->stored_path !== null
                && Storage::disk((string) config('fellowship.import.disk'))->exists($import->stored_path),
            'The stored path is missing from the import disk: '.var_export($import->stored_path, true),
        );

        $headers = $this->wizard->headers($component);
        $ledger->same('Import A detected all 12 headers', $workbook['headers'], $headers);

        $samples = $this->wizard->sampleValues($component);
        $ledger->check(
            'Import A shows a sample value for every column',
            count(array_filter($samples, static fn ($value): bool => $value !== null && $value !== '')) >= 10,
            'Samples were thin: '.json_encode($samples),
        );

        // Map: Exam is the Performance Topic; System, Year and Source become
        // dynamic filters. System is deliberately *not* the Performance Topic.
        $this->wizard->map(
            $component,
            [
                'question_text' => 'Question Text',
                'correct_answer' => 'Correct Answer',
                'difficulty' => 'Difficulty',
                'performance_topic' => 'Exam',
                'explanation' => 'Explanation',
                'option_1' => 'Option A',
                'option_2' => 'Option B',
                'option_3' => 'Option C',
                'option_4' => 'Option D',
            ],
            [
                'System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'System'],
                'Year' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Year'],
                'Source' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Source'],
            ],
        );

        $ledger->check(
            'Import A mapping reports itself complete',
            $component->instance()->isMappingComplete(),
            'Unmapped required fields: '.json_encode($component->instance()->unmappedRequiredFields()),
        );

        $this->wizard->stage($component);

        $ledger->same('Import A reaches the review step', 'review', $component->get('step'));

        $import->refresh();

        $ledger->same('Import A is staged', FellowshipImportStatus::Staged, $import->status);
        $ledger->same('Import A read every row', FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS, $import->total_rows);
        $ledger->same('Import A found every row valid', FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS, $import->valid_rows);
        $ledger->same('Import A found no invalid rows', 0, $import->invalid_rows);
        $ledger->same('Import A found no duplicates in an empty Fellowship', 0, $import->duplicate_rows);

        // Staging must not have published anything.
        $ledger->same('Staging wrote no questions', 0, $fellowship->questions()->count());
        $ledger->same('Staging created no filters', 0, $fellowship->filters()->count());
        $ledger->same('Staging left the Performance Topic undesignated', null, $fellowship->refresh()->performance_filter_id);

        // Row numbers must be the spreadsheet's own, so a reported problem can
        // be found in the file.
        $rowNumbers = $import->rows()->orderBy('row_number')->pluck('row_number')->all();
        $ledger->same('Import A row numbers start at spreadsheet row 2', 2, (int) ($rowNumbers[0] ?? 0));
        $ledger->same(
            'Import A row numbers are contiguous to the last data row',
            FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS + 1,
            (int) (end($rowNumbers) ?: 0),
        );

        // Staged payloads must be inspectable.
        $first = $import->rows()->orderBy('row_number')->first();
        $ledger->check(
            'Staged rows keep an inspectable payload',
            $first !== null && $first->payload()->questionText !== '' && count($first->payload()->options) === 4,
            'The first staged payload did not carry four options and a question text.',
        );

        // The mapping survives a round trip, so a review can be left and resumed.
        $reopened = $ledger->attempt(
            'Import A can be resumed with its mapping intact',
            fn () => $this->wizard->reopen($fellowship, $importId),
        );

        if ($reopened !== null) {
            $ledger->same('Resumed import A opens on review', 'review', $reopened->get('step'));

            // Driving the component directly is not enough to prove the Resume
            // action works: a test harness hands mount parameters to the page in
            // a way a browser never does, so the property would be populated
            // here even with no query-string binding at all. The binding itself
            // is what the admin's Resume link depends on.
            $ledger->check(
                'The import id is bound to the query string, so Resume actually resumes',
                (new \ReflectionProperty(ImportFellowshipQuestions::class, 'import'))
                    ->getAttributes(Url::class) !== [],
                'ImportFellowshipQuestions::$import carries no #[Url] attribute, so the import history\'s '
                    .'Resume action would drop the admin back on the upload step with the mapping lost.',
            );
            $ledger->check(
                'Resumed import A still maps Exam to the Performance Topic',
                ($import->header_map['Exam']['target'] ?? null) === FellowshipImportMappingTarget::PerformanceTopic->value,
                'Stored header map: '.json_encode($import->header_map),
            );
            $ledger->check(
                'Resumed import A still maps System as a dynamic filter, not the topic',
                ($import->header_map['System']['target'] ?? null) === FellowshipImportMappingTarget::NewFilter->value,
                'System was mapped to '.($import->header_map['System']['target'] ?? 'nothing'),
            );
        }

        // Confirm.
        $this->wizard->confirm($component);

        $import->refresh();
        $fellowship->refresh();

        $ledger->same('Import A completed', FellowshipImportStatus::Completed, $import->status);
        $ledger->same('Import A created every question', FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS, $import->created_questions);
        $ledger->same('Import A updated nothing', 0, $import->updated_questions);
        $ledger->same('Import A skipped nothing', 0, $import->skipped_questions);
        $ledger->check('Import A recorded a confirmation time', $import->confirmed_at !== null, 'confirmed_at is null.');

        $ledger->same(
            'Fellowship holds every imported question',
            FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS,
            $fellowship->questions()->count(),
        );

        $this->assertQuestionShape($context, 'Import A');
        $this->assertFiltersAfterA($context);
    }

    /**
     * Every stored question must be answerable: two or more options, exactly one
     * of them correct, and a Performance Topic belonging to this Fellowship's
     * own performance filter.
     */
    private function assertQuestionShape(FellowshipSmokeContext $context, string $label): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $id = $fellowship->getKey();

        $withoutTwoOptions = (int) DB::table('fellowship_questions as q')
            ->where('q.fellowship_id', $id)
            ->whereNull('q.deleted_at')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id) < 2')
            ->count();

        $ledger->same($label.': every question has at least two options', 0, $withoutTwoOptions);

        $withoutExactlyOneCorrect = (int) DB::table('fellowship_questions as q')
            ->where('q.fellowship_id', $id)
            ->whereNull('q.deleted_at')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id and o.is_correct = 1) <> 1')
            ->count();

        $ledger->same($label.': every question has exactly one correct option', 0, $withoutExactlyOneCorrect);

        $withoutTopic = (int) FellowshipQuestion::query()
            ->forFellowship($id)
            ->whereNull('performance_filter_value_id')
            ->count();

        $ledger->same($label.': every question carries a Performance Topic', 0, $withoutTopic);

        $performanceFilterId = $fellowship->refresh()->performance_filter_id;

        if ($performanceFilterId !== null) {
            $foreignTopic = (int) DB::table('fellowship_questions as q')
                ->join('fellowship_filter_values as v', 'v.id', '=', 'q.performance_filter_value_id')
                ->where('q.fellowship_id', $id)
                ->whereNull('q.deleted_at')
                ->where('v.fellowship_filter_id', '<>', $performanceFilterId)
                ->count();

            $ledger->same($label.': no question is filed under another filter as its topic', 0, $foreignTopic);
        }
    }

    /**
     * The filters workbook A should have produced, and the Performance Topic
     * designation it should have set.
     */
    private function assertFiltersAfterA(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $fellowship->refresh();
        $performance = $fellowship->performanceFilter;

        $ledger->check('Import A designated a Performance Topic filter', $performance !== null, 'performance_filter_id is still null.');
        $ledger->same('The Performance Topic filter is the mapped Exam column', 'Exam', $performance?->name);
        $ledger->same('The Performance Topic filter is flagged as such', true, $performance?->is_performance);

        $names = $fellowship->filters()->pluck('name')->sort()->values()->all();
        $ledger->same('Import A created exactly the four mapped filters', ['Exam', 'Source', 'System', 'Year'], $names);

        $dynamic = $fellowship->dynamicFilters()->pluck('name')->sort()->values()->all();
        $ledger->same('System, Year and Source are dynamic filters', ['Source', 'System', 'Year'], $dynamic);

        $examValues = $performance?->values()->pluck('value')->sort()->values()->all() ?? [];
        $ledger->same('Exam carries the three exam values', ['MRCP', 'MRCS', 'UKMLA'], $examValues);

        $yearFilter = $fellowship->filters()->where('name', 'Year')->first();
        $ledger->same(
            'Year carries the five year values',
            ['2021', '2022', '2023', '2024', '2025'],
            $yearFilter?->values()->pluck('value')->sort()->values()->all() ?? [],
        );

        // Every question should carry a value for each of the three dynamic
        // filters, since workbook A fills all of them.
        $expected = FellowshipSmokeWorkbooks::WORKBOOK_A_ROWS * 3;
        $actual = (int) DB::table('fellowship_question_filter_values as p')
            ->join('fellowship_questions as q', 'q.id', '=', 'p.fellowship_question_id')
            ->where('q.fellowship_id', $fellowship->getKey())
            ->count();

        $ledger->same('Import A filed every question under all three dynamic filters', $expected, $actual);

        $ledger->note('Fellowship filters after A', implode(', ', $names));
    }

    // ── Step 3: mapping guards ────────────────────────────────────────────────

    /**
     * What the mapping step must refuse: a missing required field, one column
     * claimed by two fields, and fewer than two answer-option columns.
     */
    private function mappingGuards(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Mapping guards');

        $fellowship = $context->fellowship;
        $workbook = $context->workbooks['A'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('Mapping guards', 'The Fellowship or workbook A is unavailable.');

            return;
        }

        $questionsBefore = $fellowship->questions()->count();

        // (a) A required field left unmapped must block staging.
        $component = $ledger->attempt(
            'Incomplete mapping blocked',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($component !== null) {
            $this->wizard->map($component, [
                'question_text' => 'Question Text',
                'correct_answer' => 'Correct Answer',
                // Difficulty deliberately left unmapped.
                'performance_topic' => 'Exam',
                'option_1' => 'Option A',
                'option_2' => 'Option B',
            ]);

            $ledger->check(
                'Mapping with no Difficulty column reports itself incomplete',
                ! $component->instance()->isMappingComplete(),
                'A mapping missing Difficulty was reported complete.',
            );

            $ledger->check(
                'Confirmation is refused while the mapping is incomplete',
                ! $component->instance()->canConfirm(),
                'canConfirm() was true with an incomplete mapping.',
            );

            $blocked = false;

            try {
                $this->wizard->stage($component);
            } catch (\Throwable) {
                $blocked = true;
            }

            $ledger->check(
                'Staging an incomplete mapping does not reach review',
                $blocked || $component->get('step') !== 'review',
                'An incomplete mapping reached the review step.',
            );
        }

        // (b) One column claimed by two system fields must be refused, not
        //     silently resolved in favour of one of them.
        $second = $ledger->attempt(
            'Column claimed twice refused',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($second !== null) {
            $refused = false;
            $detail = '';

            try {
                $this->wizard->map($second, [
                    'question_text' => 'Question Text',
                    'correct_answer' => 'Correct Answer',
                    'difficulty' => 'Difficulty',
                    'performance_topic' => 'Exam',
                    // Option 1 and option 2 both claim the same column.
                    'option_1' => 'Option A',
                    'option_2' => 'Option A',
                ]);

                $this->wizard->stage($second);
            } catch (ValidationException $exception) {
                $refused = true;
                $detail = implode(' ', $exception->validator->errors()->all());
            } catch (\Throwable $exception) {
                $detail = class_basename($exception).': '.$exception->getMessage();
            }

            $ledger->check(
                'A column claimed by two fields is refused',
                $refused || $second->get('step') !== 'review',
                'Two fields claimed one column and staging went ahead. '.$detail,
            );
        }

        $ledger->same(
            'Blocked mappings wrote no questions',
            $questionsBefore,
            $fellowship->questions()->count(),
        );
    }

    // ── Step 4: workbook D, the mapping edge cases ────────────────────────────

    /**
     * The option-count and header-naming edge cases, each into the isolation
     * Fellowship so they cannot disturb the bank the student studies.
     */
    private function importMappingEdgeCases(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Mapping edge cases (D)');

        $fellowship = $context->otherFellowship;

        if ($fellowship === null) {
            $ledger->skip('Mapping edge cases', 'The isolation Fellowship is unavailable.');

            return;
        }

        // D1 — exactly two answer options.
        $this->importEdgeCase(
            $context,
            $fellowship,
            'D1',
            'Two answer-option columns',
            [
                'question_text' => 'Question Text',
                'correct_answer' => 'Correct Answer',
                'difficulty' => 'Difficulty',
                'performance_topic' => 'Exam',
                'option_1' => 'True',
                'option_2' => 'False',
            ],
            ['System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'System']],
            expectedOptions: 2,
        );

        // D2 — six answer options, addressed by letters past D and numbers past 4.
        $this->importEdgeCase(
            $context,
            $fellowship,
            'D2',
            'Six answer-option columns',
            [
                'question_text' => 'Question Text',
                'correct_answer' => 'Correct Answer',
                'difficulty' => 'Difficulty',
                'performance_topic' => 'Exam',
                'option_1' => 'Opt 1',
                'option_2' => 'Opt 2',
                'option_3' => 'Opt 3',
                'option_4' => 'Opt 4',
                'option_5' => 'Opt 5',
                'option_6' => 'Opt 6',
            ],
            [
                'System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => null],
                'Year' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Year'],
            ],
            expectedOptions: 6,
        );

        // D3 — every column renamed and reordered, three columns ignored.
        $this->importEdgeCase(
            $context,
            $fellowship,
            'D3',
            'Renamed and reordered columns with ignored extras',
            [
                'question_text' => 'Vignette',
                'correct_answer' => 'Answer Key',
                'difficulty' => 'Complexity',
                'performance_topic' => 'Specialty Track',
                'explanation' => 'Teaching Note',
                'option_1' => 'Possible Answer One',
                'option_2' => 'Possible Answer Two',
                'option_3' => 'Possible Answer Three',
                'option_4' => 'Possible Answer Four',
            ],
            // Internal Ref, Author Initials and Review Status are left to be
            // ignored, which is the default for an unrecognised column.
            [],
            expectedOptions: 4,
        );

        // D4 — a repeated header name and a blank header cell between populated
        //      columns, both of which the reader has to keep addressable.
        $this->importEdgeCase(
            $context,
            $fellowship,
            'D4',
            'Duplicate and blank header cells',
            [
                'question_text' => 'Question',
                'correct_answer' => 'Answer',
                'difficulty' => 'Difficulty',
                'performance_topic' => 'Exam',
                'option_1' => 'Option',
                'option_2' => 'Option (2)',
                'option_3' => 'Column 4',
            ],
            [],
            expectedOptions: 3,
        );
    }

    /**
     * Import one edge-case workbook and check it produced questions of the
     * expected shape.
     *
     * @param  array<string, string>  $systemFields
     * @param  array<string, array{target: string, filter_id?: int|null, filter_name?: string|null}>  $extraColumns
     */
    private function importEdgeCase(
        FellowshipSmokeContext $context,
        Fellowship $fellowship,
        string $key,
        string $label,
        array $systemFields,
        array $extraColumns,
        int $expectedOptions,
    ): void {
        $ledger = $context->ledger;
        $workbook = $context->workbooks[$key] ?? null;

        if ($workbook === null) {
            $ledger->skip($label, 'Workbook '.$key.' is unavailable.');

            return;
        }

        $before = $fellowship->questions()->count();

        $component = $ledger->attempt($label.': uploaded', fn () => $this->wizard->uploadAndRead(
            $this->wizard->open($fellowship),
            $workbook['path'],
        ));

        if ($component === null) {
            return;
        }

        $headers = $this->wizard->headers($component);
        $ledger->same($label.': headers detected', $workbook['headers'] === [] ? $headers : count($workbook['headers']), count($headers));

        // An existing-filter mapping needs the id the Fellowship actually has.
        foreach ($extraColumns as $header => $entry) {
            if (($entry['target'] ?? null) === FellowshipImportMappingTarget::ExistingFilter->value && ($entry['filter_id'] ?? null) === null) {
                $existing = FellowshipFilter::query()
                    ->where('fellowship_id', $fellowship->getKey())
                    ->where('name', $header)
                    ->first();

                if ($existing === null) {
                    // Nothing to reuse yet, so create it instead.
                    $extraColumns[$header] = [
                        'target' => FellowshipImportMappingTarget::NewFilter->value,
                        'filter_name' => $header,
                    ];

                    continue;
                }

                $extraColumns[$header]['filter_id'] = $existing->getKey();
                $ledger->pass($label.': reused the existing "'.$header.'" filter by id');
            }
        }

        $this->wizard->map($component, $systemFields, $extraColumns);

        $ledger->check(
            $label.': mapping complete',
            $component->instance()->isMappingComplete(),
            'Unmapped: '.json_encode($component->instance()->unmappedRequiredFields()),
        );

        $this->wizard->stage($component);

        $importId = (int) $component->get('import');
        $import = FellowshipImport::query()->find($importId);

        if ($import === null) {
            $ledger->fail($label.': staged', 'No import record found after staging.');

            return;
        }

        $context->imports[$key] = $importId;

        $ledger->same($label.': every row valid', $workbook['rows'], $import->valid_rows + $import->duplicate_rows);
        $ledger->same($label.': no invalid rows', 0, $import->invalid_rows);

        // Within-file duplicates may exist for an edge-case file; decide them
        // explicitly rather than letting confirmation be blocked.
        $this->decideAllPending($component, $import, FellowshipImportRowDecision::KeepBoth);

        $this->wizard->confirm($component);

        $import->refresh();

        $ledger->same($label.': import completed', FellowshipImportStatus::Completed, $import->status);

        $added = $fellowship->questions()->count() - $before;
        $ledger->same($label.': questions inserted', $workbook['rows'], $added);

        // Option counts on the questions this import produced.
        $questionIds = $import->rows()
            ->whereNotNull('fellowship_question_id')
            ->pluck('fellowship_question_id')
            ->all();

        if ($questionIds !== []) {
            $optionCounts = DB::table('fellowship_question_options')
                ->select('fellowship_question_id', DB::raw('count(*) as total'))
                ->whereIn('fellowship_question_id', $questionIds)
                ->groupBy('fellowship_question_id')
                ->pluck('total')
                ->unique()
                ->values()
                ->all();

            $ledger->same($label.': every question carries '.$expectedOptions.' options', [$expectedOptions], array_map('intval', $optionCounts));

            $wrongCorrect = (int) DB::table('fellowship_question_options')
                ->select('fellowship_question_id')
                ->whereIn('fellowship_question_id', $questionIds)
                ->where('is_correct', true)
                ->groupBy('fellowship_question_id')
                ->havingRaw('count(*) <> 1')
                ->get()
                ->count();

            $ledger->same($label.': exactly one correct option per question', 0, $wrongCorrect);
        }

        if ($key === 'D3') {
            // The ignored columns must not have become filters.
            $filterNames = $fellowship->filters()->pluck('name')->all();

            foreach (['Internal Ref', 'Author Initials', 'Review Status'] as $ignored) {
                $ledger->check(
                    'D3: "'.$ignored.'" was ignored rather than made a filter',
                    ! in_array($ignored, $filterNames, true),
                    'An ignored column became the filter "'.$ignored.'".',
                );
            }
        }
    }

    // ── Step 5: workbook B, the duplicate review ──────────────────────────────

    /**
     * Workbook B: duplicates of A mixed with new questions, renamed and
     * reordered columns, a brand-new optional filter, and blank optional cells.
     *
     * Every supported decision is exercised, and confirmation is checked to be
     * blocked while any duplicate is still pending.
     */
    private function importWorkbookB(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Import B (duplicate review)');

        $fellowship = $context->fellowship;
        $workbook = $context->workbooks['B'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('Workbook B imported', 'The Fellowship or workbook B is unavailable.');

            return;
        }

        $performanceFilterBefore = $fellowship->refresh()->performance_filter_id;
        $questionsBefore = $fellowship->questions()->count();

        $component = $ledger->attempt(
            'Workbook B uploaded',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($component === null) {
            return;
        }

        $headers = $this->wizard->headers($component);
        $ledger->check(
            'Workbook B headers differ from A yet are all detected',
            in_array('Stem', $headers, true) && in_array('Clinical Setting', $headers, true) && count($headers) === 13,
            'Headers read: '.implode(' | ', $headers),
        );

        // Reuse the Fellowship's existing System/Year/Source filters by id, even
        // though B calls those columns something else — which is the point.
        $existing = $fellowship->dynamicFilters()->pluck('id', 'name')->all();

        $this->wizard->map(
            $component,
            [
                'question_text' => 'Stem',
                'correct_answer' => 'Answer',
                'difficulty' => 'Level',
                // Examination feeds the same Performance Topic filter as A's Exam.
                'performance_topic' => 'Examination',
                'explanation' => 'Rationale',
                'option_1' => 'Choice 1',
                'option_2' => 'Choice 2',
                'option_3' => 'Choice 3',
                'option_4' => 'Choice 4',
            ],
            [
                'Body System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['System'] ?? null],
                'Exam Year' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['Year'] ?? null],
                'Reference' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['Source'] ?? null],
                // A filter the Fellowship has never had.
                'Clinical Setting' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'Clinical Setting'],
            ],
        );

        $this->wizard->stage($component);

        $importId = (int) $component->get('import');
        $import = FellowshipImport::query()->find($importId);

        if ($import === null) {
            $ledger->fail('Workbook B staged', 'No import record after staging.');

            return;
        }

        $context->imports['B'] = $importId;
        $ledger->note('Import B ID', $importId);

        $ledger->same('Import B read every row', $workbook['rows'], $import->total_rows);
        $ledger->same(
            'Import B found the 22 duplicates of A plus 4 within-file repeats',
            FellowshipSmokeWorkbooks::WORKBOOK_B_DUPLICATES + 4,
            $import->duplicate_rows,
        );
        $ledger->same('Import B found the new questions valid', FellowshipSmokeWorkbooks::WORKBOOK_B_NEW, $import->valid_rows);
        $ledger->same('Import B found no invalid rows', 0, $import->invalid_rows);

        $ledger->same('Staging B wrote no new questions', $questionsBefore, $fellowship->questions()->count());
        $ledger->check(
            'Staging B created no "Clinical Setting" filter',
            ! $fellowship->filters()->where('name', 'Clinical Setting')->exists(),
            'Staging published a filter before confirmation.',
        );

        // Duplicate scopes: against stored questions, and within the file.
        $existingScope = $import->rows()->where('duplicate_scope', FellowshipImportDuplicateScope::Existing)->count();
        $fileScope = $import->rows()->where('duplicate_scope', FellowshipImportDuplicateScope::File)->count();

        $ledger->same('Import B matched 22 duplicates against stored questions', FellowshipSmokeWorkbooks::WORKBOOK_B_DUPLICATES, $existingScope);
        $ledger->same('Import B matched 4 duplicates within the file itself', 4, $fileScope);

        $this->assertDuplicateComparisons($context, $component, $import);
        $this->assertPendingDecisionsBlockConfirmation($context, $component, $import);

        $decisions = $this->exerciseDuplicateDecisions($context, $component, $import);

        // Nothing should still be pending.
        $import->refresh();
        $ledger->same('Every duplicate has a decision before confirmation', 0, $import->unresolvedDuplicatesCount());
        $ledger->check('Confirmation is now allowed', $component->instance()->canConfirm(), 'canConfirm() is still false.');

        $this->confirmWorkbookB($context, $component, $import, $decisions, $performanceFilterBefore);
    }

    /**
     * The side-by-side a duplicate is decided from must actually show the
     * difference, including a changed option set and a moved correct answer.
     */
    private function assertDuplicateComparisons(
        FellowshipSmokeContext $context,
        Testable $component,
        FellowshipImport $import,
    ): void {
        $ledger = $context->ledger;

        // The first duplicate of A is one whose options and correct answer were
        // deliberately changed in workbook B, so its comparison must report both
        // as conflicting rather than merely matching on the question text.
        $row = $import->rows()
            ->where('duplicate_scope', FellowshipImportDuplicateScope::Existing)
            ->whereNotNull('duplicate_of_question_id')
            ->orderBy('row_number')
            ->first();

        if ($row === null) {
            $ledger->skip('Duplicate comparison shows the difference', 'No existing-scope duplicate row was staged.');

            return;
        }

        // Built by the page itself, so the admin sees exactly this.
        $comparison = $component->instance()->comparisonFor($row);

        $byLabel = [];

        foreach ($comparison->fields as $field) {
            $byLabel[$field['label']] = $field;
        }

        $ledger->check(
            'Duplicate comparison puts the stored question beside the imported one',
            ($byLabel['Question text']['existing'] ?? null) !== null
                && ($byLabel['Question text']['imported'] ?? null) !== null,
            'The comparison did not carry both question texts: '.json_encode($byLabel['Question text'] ?? null),
        );

        $ledger->check(
            'Duplicate comparison reports the changed explanation',
            ($byLabel['Explanation']['conflicts'] ?? false) === true,
            'The comparison did not notice the changed explanation: '.json_encode($byLabel['Explanation'] ?? null),
        );

        $ledger->check(
            'Duplicate comparison reports the changed answer options',
            $comparison->optionsConflict,
            'The comparison did not notice the changed option set.',
        );

        $ledger->check(
            'Duplicate comparison reports the moved correct answer',
            $comparison->correctAnswerConflicts,
            'The comparison did not notice the moved correct answer.',
        );

        $ledger->check(
            'Duplicate comparison reports an overall conflict',
            $comparison->hasConflicts(),
            'The comparison reported no conflict at all.',
        );

        $ledger->check(
            'Duplicate comparison lists both option sets side by side',
            $comparison->options !== [],
            'The comparison carried no options to compare.',
        );
    }

    /**
     * A pending duplicate must block confirmation — on the page and in the
     * service, so the rule holds however confirmation was reached.
     */
    private function assertPendingDecisionsBlockConfirmation(
        FellowshipSmokeContext $context,
        Testable $component,
        FellowshipImport $import,
    ): void {
        $ledger = $context->ledger;

        $pending = $import->unresolvedDuplicatesCount();

        $ledger->check(
            'Every duplicate starts with no decision',
            $pending === $import->duplicate_rows,
            sprintf('%d of %d duplicates were pending; none should have a default.', $pending, $import->duplicate_rows),
        );

        $ledger->check(
            'The page refuses confirmation while duplicates are pending',
            ! $component->instance()->canConfirm(),
            'canConfirm() was true with '.$pending.' pending duplicates.',
        );

        $questionsBefore = $import->fellowship->questions()->count();

        // Pressing Confirm anyway must change nothing.
        $this->wizard->confirm($component);
        $import->refresh();

        $ledger->same('A blocked confirmation leaves the import staged', FellowshipImportStatus::Staged, $import->status);
        $ledger->same('A blocked confirmation writes no questions', $questionsBefore, $import->fellowship->questions()->count());

        // The service refuses independently of the page.
        $refused = false;

        try {
            app(FellowshipImportConfirmationService::class)->confirm($import);
        } catch (FellowshipImportException) {
            $refused = true;
        } catch (\Throwable $exception) {
            $ledger->fail(
                'The confirmation service refuses pending duplicates',
                'Unexpected '.class_basename($exception).': '.$exception->getMessage(),
            );
        }

        $ledger->check(
            'The confirmation service refuses pending duplicates independently of the page',
            $refused,
            'The service confirmed an import with pending duplicate decisions.',
        );
    }

    /**
     * Work through every decision the review screen supports, individually and
     * in bulk, including changing and clearing one.
     *
     * @return array{skip: list<int>, update: list<int>, keep: list<int>, file: list<int>}
     */
    private function exerciseDuplicateDecisions(
        FellowshipSmokeContext $context,
        Testable $component,
        FellowshipImport $import,
    ): array {
        $ledger = $context->ledger;

        $existingRows = $import->rows()
            ->where('duplicate_scope', FellowshipImportDuplicateScope::Existing)
            ->orderBy('row_number')
            ->get();

        $fileRows = $import->rows()
            ->where('duplicate_scope', FellowshipImportDuplicateScope::File)
            ->orderBy('row_number')
            ->get();

        // What each row may be offered.
        $ledger->check(
            'Update Existing is offered for a duplicate of a stored question',
            $existingRows->isNotEmpty() && $existingRows->every(
                fn (FellowshipImportRow $row): bool => $row->allows(FellowshipImportRowDecision::UpdateExisting),
            ),
            'A stored-question duplicate was not offered Update Existing.',
        );

        $ledger->check(
            'Update Existing is never offered for a within-file duplicate',
            $fileRows->isNotEmpty() && $fileRows->every(
                fn (FellowshipImportRow $row): bool => ! $row->allows(FellowshipImportRowDecision::UpdateExisting),
            ),
            'A within-file duplicate was offered Update Existing, which has nothing to update.',
        );

        $ledger->check(
            'Skip and Keep Both are offered for a within-file duplicate',
            $fileRows->every(fn (FellowshipImportRow $row): bool => $row->allows(FellowshipImportRowDecision::Skip)
                && $row->allows(FellowshipImportRowDecision::KeepBoth)),
            'A within-file duplicate was not offered Skip and Keep Both.',
        );

        // Individual decisions: one of each.
        $update = $existingRows->take(8)->pluck('id')->map('intval')->all();
        $skip = $existingRows->slice(8, 7)->pluck('id')->map('intval')->all();
        $keep = $existingRows->slice(15)->pluck('id')->map('intval')->all();
        $fileIds = $fileRows->pluck('id')->map('intval')->all();

        // (a) Individual Update Existing.
        $firstUpdate = $update[0] ?? null;

        if ($firstUpdate !== null) {
            $this->wizard->decide($component, $firstUpdate, FellowshipImportRowDecision::UpdateExisting->value);
            $ledger->same(
                'Individual Update Existing recorded',
                FellowshipImportRowDecision::UpdateExisting,
                FellowshipImportRow::query()->find($firstUpdate)?->decision,
            );
        }

        // (b) Changing a decision before confirmation.
        if ($firstUpdate !== null) {
            $this->wizard->decide($component, $firstUpdate, FellowshipImportRowDecision::KeepBoth->value);
            $ledger->same(
                'A decision can be changed before confirmation',
                FellowshipImportRowDecision::KeepBoth,
                FellowshipImportRow::query()->find($firstUpdate)?->decision,
            );

            // (c) Clearing it puts the row back to awaiting a decision.
            $this->wizard->decide($component, $firstUpdate, '');
            $cleared = FellowshipImportRow::query()->find($firstUpdate);

            $ledger->same('A decision can be cleared', null, $cleared?->decision);
            $ledger->check(
                'A cleared decision blocks confirmation again',
                $cleared !== null && $cleared->awaitsDecision() && ! $component->instance()->canConfirm(),
                'Clearing a decision did not re-block confirmation.',
            );

            // Put it back to Update Existing for the confirmation that follows.
            $this->wizard->decide($component, $firstUpdate, FellowshipImportRowDecision::UpdateExisting->value);
        }

        // (d) Individual Skip.
        $firstSkip = $skip[0] ?? null;

        if ($firstSkip !== null) {
            $this->wizard->decide($component, $firstSkip, FellowshipImportRowDecision::Skip->value);
            $ledger->same(
                'Individual Skip recorded',
                FellowshipImportRowDecision::Skip,
                FellowshipImportRow::query()->find($firstSkip)?->decision,
            );
        }

        // (e) Individual Keep Both.
        $firstKeep = $keep[0] ?? null;

        if ($firstKeep !== null) {
            $this->wizard->decide($component, $firstKeep, FellowshipImportRowDecision::KeepBoth->value);
            $ledger->same(
                'Individual Keep Both recorded',
                FellowshipImportRowDecision::KeepBoth,
                FellowshipImportRow::query()->find($firstKeep)?->decision,
            );
        }

        // (f) Select All, then Clear Selection.
        $this->wizard->selectAll($component);
        $selectedAll = $component->instance()->selectedCount();

        $ledger->same(
            'Select All ticks every duplicate row',
            $import->duplicate_rows,
            $selectedAll,
        );

        // Update Existing must disappear from the bulk options once a within-file
        // duplicate is in the selection.
        $bulkOptionsWithFileRow = array_keys($component->instance()->bulkDecisionOptions());

        $ledger->check(
            'Bulk Update Existing is withheld when the selection includes a within-file duplicate',
            ! in_array(FellowshipImportRowDecision::UpdateExisting->value, $bulkOptionsWithFileRow, true),
            'Bulk options offered Update Existing for a selection containing a within-file duplicate: '
                .implode(', ', $bulkOptionsWithFileRow),
        );

        $this->wizard->clearSelection($component);
        $ledger->same('Clear Selection unticks everything', 0, $component->instance()->selectedCount());
        $ledger->same('Clear Selection resets Select All', false, $component->get('selectAll'));

        // (g) Bulk Update Existing on eligible rows only.
        $bulkUpdate = array_slice($update, 1);

        if ($bulkUpdate !== []) {
            $this->wizard->select($component, $bulkUpdate);

            $options = array_keys($component->instance()->bulkDecisionOptions());
            $ledger->check(
                'Bulk Update Existing is offered for an all-eligible selection',
                in_array(FellowshipImportRowDecision::UpdateExisting->value, $options, true),
                'Bulk options were '.implode(', ', $options),
            );

            $this->wizard->applyBulk($component, FellowshipImportRowDecision::UpdateExisting->value);

            $applied = FellowshipImportRow::query()
                ->whereIn('id', $bulkUpdate)
                ->where('decision', FellowshipImportRowDecision::UpdateExisting)
                ->count();

            $ledger->same('Bulk Update Existing applied to every selected row', count($bulkUpdate), $applied);

            $this->wizard->clearSelection($component);
        }

        // (h) Bulk Skip.
        $bulkSkip = array_slice($skip, 1);

        if ($bulkSkip !== []) {
            $this->wizard->select($component, $bulkSkip);
            $this->wizard->applyBulk($component, FellowshipImportRowDecision::Skip->value);

            $applied = FellowshipImportRow::query()
                ->whereIn('id', $bulkSkip)
                ->where('decision', FellowshipImportRowDecision::Skip)
                ->count();

            $ledger->same('Bulk Skip applied to every selected row', count($bulkSkip), $applied);

            $this->wizard->clearSelection($component);
        }

        // (i) Bulk Keep Both, including the within-file duplicates.
        $bulkKeep = [...array_slice($keep, 1), ...$fileIds];

        if ($bulkKeep !== []) {
            $this->wizard->select($component, $bulkKeep);
            $this->wizard->applyBulk($component, FellowshipImportRowDecision::KeepBoth->value);

            $applied = FellowshipImportRow::query()
                ->whereIn('id', $bulkKeep)
                ->where('decision', FellowshipImportRowDecision::KeepBoth)
                ->count();

            $ledger->same('Bulk Keep Both applied to every selected row', count($bulkKeep), $applied);

            $this->wizard->clearSelection($component);
        }

        // (j) A bulk decision a row cannot take leaves it alone rather than
        //     giving it something else.
        if ($fileIds !== []) {
            $target = $fileIds[0];

            // Clear it first, so "left alone" is observable.
            $this->wizard->decide($component, $target, '');
            $this->wizard->select($component, [$target]);

            app(FellowshipImportConfirmationService::class)
                ->decideMany(FellowshipImportRow::query()->whereIn('id', [$target])->get(), FellowshipImportRowDecision::UpdateExisting);

            $ledger->same(
                'Bulk Update Existing leaves an ineligible row awaiting a decision',
                null,
                FellowshipImportRow::query()->find($target)?->decision,
            );

            $this->wizard->clearSelection($component);
            $this->wizard->decide($component, $target, FellowshipImportRowDecision::KeepBoth->value);
        }

        // (k) Update Existing must be refused when the stored question is gone.
        $this->assertUpdateRefusedWhenTargetMissing($context, $import);

        return [
            'skip' => $skip,
            'update' => $update,
            'keep' => $keep,
            'file' => $fileIds,
        ];
    }

    /**
     * A row whose matched question has been force-deleted since the review must
     * not quietly become an insert.
     */
    private function assertUpdateRefusedWhenTargetMissing(FellowshipSmokeContext $context, FellowshipImport $import): void
    {
        $ledger = $context->ledger;

        $row = $import->rows()
            ->where('duplicate_scope', FellowshipImportDuplicateScope::Existing)
            ->whereNotNull('duplicate_of_question_id')
            ->orderByDesc('row_number')
            ->first();

        if ($row === null) {
            $ledger->skip('Update Existing refused when the target is gone', 'No existing-scope duplicate available.');

            return;
        }

        // Simulate the matched question having vanished, without touching a real
        // one: the row's pointer is cleared, which is what the database's
        // `SET NULL` does when the question is force-deleted.
        $originalId = $row->duplicate_of_question_id;
        $row->update(['duplicate_of_question_id' => null]);
        $row->refresh();

        $ledger->same(
            'Update Existing is not offered once the matched question is gone',
            false,
            $row->allows(FellowshipImportRowDecision::UpdateExisting),
        );

        $refused = false;

        try {
            app(FellowshipImportConfirmationService::class)
                ->decide($row, FellowshipImportRowDecision::UpdateExisting);
        } catch (FellowshipImportException) {
            $refused = true;
        }

        $ledger->check(
            'The service refuses Update Existing with no question to update',
            $refused,
            'The service accepted Update Existing for a row with no matched question.',
        );

        // Put the row back as it was.
        $row->update(['duplicate_of_question_id' => $originalId]);
    }

    /**
     * Confirm B and check each decision did exactly what it says.
     *
     * @param  array{skip: list<int>, update: list<int>, keep: list<int>, file: list<int>}  $decisions
     */
    private function confirmWorkbookB(
        FellowshipSmokeContext $context,
        Testable $component,
        FellowshipImport $import,
        array $decisions,
        ?int $performanceFilterBefore,
    ): void {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $questionsBefore = $fellowship->questions()->count();

        // What the Update Existing rows pointed at, and what those questions
        // looked like beforehand.
        $updateRows = FellowshipImportRow::query()
            ->whereIn('id', $decisions['update'])
            ->where('decision', FellowshipImportRowDecision::UpdateExisting)
            ->get();

        $beforeSnapshots = [];

        foreach ($updateRows as $row) {
            $question = FellowshipQuestion::query()->find($row->duplicate_of_question_id);

            if ($question === null) {
                continue;
            }

            $beforeSnapshots[$question->getKey()] = [
                'explanation' => $question->explanation,
                'options' => $question->options()->orderBy('order')->pluck('option_text')->all(),
                'correct' => $question->options()->where('is_correct', true)->value('option_text'),
                'row' => $row->getKey(),
            ];
        }

        // Questions the Skip rows matched, which must be untouched.
        $skipRows = FellowshipImportRow::query()
            ->whereIn('id', $decisions['skip'])
            ->where('decision', FellowshipImportRowDecision::Skip)
            ->get();

        $skippedBefore = [];

        foreach ($skipRows as $row) {
            $question = FellowshipQuestion::query()->find($row->duplicate_of_question_id);

            if ($question !== null) {
                $skippedBefore[$question->getKey()] = [
                    'explanation' => $question->explanation,
                    'updated_at' => $question->updated_at?->toIso8601String(),
                ];
            }
        }

        $this->wizard->confirm($component);

        $import->refresh();
        $fellowship->refresh();

        $ledger->same('Import B completed', FellowshipImportStatus::Completed, $import->status);

        // Outcome counts must agree with the decisions taken.
        $expectedUpdated = FellowshipImportRow::query()
            ->where('fellowship_import_id', $import->getKey())
            ->where('outcome', FellowshipImportRowOutcome::Updated)
            ->count();

        $expectedSkipped = FellowshipImportRow::query()
            ->where('fellowship_import_id', $import->getKey())
            ->where('outcome', FellowshipImportRowOutcome::Skipped)
            ->count();

        $expectedCreated = FellowshipImportRow::query()
            ->where('fellowship_import_id', $import->getKey())
            ->where('outcome', FellowshipImportRowOutcome::Created)
            ->count();

        $ledger->same('Import B summary matches its rows (created)', $expectedCreated, $import->created_questions);
        $ledger->same('Import B summary matches its rows (updated)', $expectedUpdated, $import->updated_questions);
        $ledger->same('Import B summary matches its rows (skipped)', $expectedSkipped, $import->skipped_questions);

        $failed = FellowshipImportRow::query()
            ->where('fellowship_import_id', $import->getKey())
            ->where('outcome', FellowshipImportRowOutcome::Failed)
            ->get();

        $ledger->check(
            'No row of import B failed unexpectedly',
            $failed->isEmpty(),
            'Failed rows: '.$failed->map(fn ($row): string => '#'.$row->row_number.' '.json_encode($row->errors))->implode('; '),
        );

        // Inserts: the new questions plus every Keep Both.
        $ledger->same(
            'Import B inserted the new questions and every Keep Both',
            $questionsBefore + $expectedCreated,
            $fellowship->questions()->count(),
        );

        // Update Existing changed the intended live question, and only it.
        foreach ($beforeSnapshots as $questionId => $before) {
            $question = FellowshipQuestion::query()->find($questionId);

            if ($question === null) {
                $ledger->fail('Update Existing kept the question it updated', 'Question '.$questionId.' no longer exists.');

                continue;
            }

            $ledger->check(
                'Update Existing rewrote the stored explanation',
                $question->explanation !== $before['explanation']
                    && str_starts_with((string) $question->explanation, 'Re-import revision:'),
                sprintf('Question %d explanation is "%s".', $questionId, (string) $question->explanation),
            );
        }

        if ($beforeSnapshots !== []) {
            // At least one update had its options and correct answer changed.
            $changedOptions = 0;
            $changedCorrect = 0;

            foreach ($beforeSnapshots as $questionId => $before) {
                $question = FellowshipQuestion::query()->find($questionId);

                if ($question === null) {
                    continue;
                }

                $after = $question->options()->orderBy('order')->pluck('option_text')->all();
                $correct = $question->options()->where('is_correct', true)->value('option_text');

                if ($after !== $before['options']) {
                    $changedOptions++;
                }

                if ($correct !== $before['correct']) {
                    $changedCorrect++;
                }
            }

            $ledger->check(
                'Update Existing replaced the answer options where the file changed them',
                $changedOptions > 0,
                'No updated question had its option set rewritten.',
            );

            $ledger->check(
                'Update Existing moved the correct answer where the file moved it',
                $changedCorrect > 0,
                'No updated question had its correct answer moved.',
            );

            // Every updated question still has exactly one correct option.
            $ids = array_keys($beforeSnapshots);

            $broken = (int) DB::table('fellowship_question_options')
                ->select('fellowship_question_id')
                ->whereIn('fellowship_question_id', $ids)
                ->where('is_correct', true)
                ->groupBy('fellowship_question_id')
                ->havingRaw('count(*) <> 1')
                ->get()
                ->count();

            $ledger->same('Every updated question still has exactly one correct option', 0, $broken);
        }

        // Skip changed nothing.
        foreach ($skippedBefore as $questionId => $before) {
            $question = FellowshipQuestion::query()->find($questionId);

            $ledger->check(
                'A skipped duplicate left its stored question untouched',
                $question !== null
                    && $question->explanation === $before['explanation']
                    && $question->updated_at?->toIso8601String() === $before['updated_at'],
                'Question '.$questionId.' changed although its duplicate was skipped.',
            );
        }

        // The Performance Topic designation must not have moved.
        $ledger->same(
            'Import B kept the existing Performance Topic filter',
            $performanceFilterBefore,
            $fellowship->performance_filter_id,
        );

        $ledger->same(
            'Examination mapped onto the same Exam filter rather than forking it',
            'Exam',
            $fellowship->performanceFilter?->name,
        );

        // The new filter arrived; the reused ones were not duplicated.
        $names = $fellowship->filters()->pluck('name')->sort()->values()->all();

        $ledger->same(
            'Import B added only the new Clinical Setting filter',
            ['Clinical Setting', 'Exam', 'Source', 'System', 'Year'],
            $names,
        );

        $this->assertOptionalFilterBehaviour($context, $import);
        $this->assertQuestionShape($context, 'Import B');
    }

    /**
     * Mapped-and-empty clears a value; a filter the file never mentioned keeps
     * the one it had.
     */
    private function assertOptionalFilterBehaviour(FellowshipSmokeContext $context, FellowshipImport $import): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $clinicalSetting = $fellowship->filters()->where('name', 'Clinical Setting')->first();

        if ($clinicalSetting === null) {
            $ledger->skip('Blank optional cells are handled', 'The Clinical Setting filter was not created.');

            return;
        }

        // Rows whose Clinical Setting cell was blank must have produced no value
        // for that filter.
        $blankRows = $import->rows()
            ->whereNotNull('fellowship_question_id')
            ->get()
            ->filter(function (FellowshipImportRow $row) use ($clinicalSetting): bool {
                foreach ($row->payload()->filters as $filter) {
                    if (($filter['filter_name'] ?? null) === $clinicalSetting->name) {
                        return $filter['value'] === null;
                    }
                }

                return false;
            });

        if ($blankRows->isEmpty()) {
            $ledger->skip('A blank optional cell stores no value', 'No confirmed row had a blank Clinical Setting cell.');
        } else {
            $withValue = (int) DB::table('fellowship_question_filter_values')
                ->whereIn('fellowship_question_id', $blankRows->pluck('fellowship_question_id')->all())
                ->where('fellowship_filter_id', $clinicalSetting->getKey())
                ->count();

            $ledger->same('A blank optional cell stores no filter value', 0, $withValue);
        }

        // Questions inserted by A kept their Year value even though B's own rows
        // did not all carry one.
        $yearFilter = $fellowship->filters()->where('name', 'Year')->first();

        if ($yearFilter !== null) {
            $aImportId = $context->imports['A'] ?? null;

            if ($aImportId !== null) {
                $aQuestionIds = FellowshipImportRow::query()
                    ->where('fellowship_import_id', $aImportId)
                    ->whereNotNull('fellowship_question_id')
                    ->pluck('fellowship_question_id')
                    ->all();

                // Only the questions B did not touch.
                $touched = FellowshipImportRow::query()
                    ->where('fellowship_import_id', $import->getKey())
                    ->whereNotNull('duplicate_of_question_id')
                    ->pluck('duplicate_of_question_id')
                    ->all();

                $untouched = array_values(array_diff($aQuestionIds, $touched));

                if ($untouched !== []) {
                    $missingYear = count($untouched) - (int) DB::table('fellowship_question_filter_values')
                        ->whereIn('fellowship_question_id', $untouched)
                        ->where('fellowship_filter_id', $yearFilter->getKey())
                        ->count();

                    $ledger->same(
                        'Questions untouched by import B kept their Year value',
                        0,
                        $missingYear,
                    );
                }
            }
        }

        // No question may hold a value belonging to another filter.
        $crossFilter = (int) DB::table('fellowship_question_filter_values as p')
            ->join('fellowship_filter_values as v', 'v.id', '=', 'p.fellowship_filter_value_id')
            ->join('fellowship_questions as q', 'q.id', '=', 'p.fellowship_question_id')
            ->where('q.fellowship_id', $fellowship->getKey())
            ->whereColumn('v.fellowship_filter_id', '<>', 'p.fellowship_filter_id')
            ->count();

        $ledger->same('No question holds a value from a different filter', 0, $crossFilter);

        // And no value belonging to another Fellowship.
        $foreignFellowship = (int) DB::table('fellowship_question_filter_values as p')
            ->join('fellowship_filters as f', 'f.id', '=', 'p.fellowship_filter_id')
            ->join('fellowship_questions as q', 'q.id', '=', 'p.fellowship_question_id')
            ->whereColumn('f.fellowship_id', '<>', 'q.fellowship_id')
            ->count();

        $ledger->same('No question is filed under another Fellowship\'s filter', 0, $foreignFellowship);
    }

    // ── Step 6: workbook C, the validation failures ───────────────────────────

    /**
     * Workbook C: every invalid row rejected with a reason that names its
     * problem, while the valid rows beside them still go in.
     */
    private function importWorkbookC(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Import C (validation)');

        $fellowship = $context->fellowship;
        $workbook = $context->workbooks['C'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('Workbook C imported', 'The Fellowship or workbook C is unavailable.');

            return;
        }

        $questionsBefore = $fellowship->questions()->count();

        $component = $ledger->attempt(
            'Workbook C uploaded',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($component === null) {
            return;
        }

        $existing = $fellowship->dynamicFilters()->pluck('id', 'name')->all();

        $this->wizard->map(
            $component,
            [
                'question_text' => 'Question Text',
                'correct_answer' => 'Correct Answer',
                'difficulty' => 'Difficulty',
                'performance_topic' => 'Exam',
                'explanation' => 'Explanation',
                'option_1' => 'Option A',
                'option_2' => 'Option B',
                'option_3' => 'Option C',
                'option_4' => 'Option D',
            ],
            [
                'System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['System'] ?? null],
                'Year' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['Year'] ?? null],
                'Source' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing['Source'] ?? null],
            ],
        );

        $this->wizard->stage($component);

        $importId = (int) $component->get('import');
        $import = FellowshipImport::query()->find($importId);

        if ($import === null) {
            $ledger->fail('Workbook C staged', 'No import record after staging.');

            return;
        }

        $context->imports['C'] = $importId;
        $ledger->note('Import C ID', $importId);

        // One line of workbook C is wholly blank. The reader treats a blank line
        // as spreadsheet padding rather than a row, so it is skipped rather than
        // reported as invalid — which is why the readable count is one short of
        // the lines written.
        $ledger->same('Import C read every non-blank row', $workbook['readable_rows'], $import->total_rows);
        $ledger->same(
            'Import C skipped the wholly blank line rather than reporting it',
            1,
            $workbook['rows'] - $import->total_rows,
        );
        $ledger->check(
            'Import C found invalid rows',
            $import->invalid_rows >= 18,
            'Only '.$import->invalid_rows.' rows were rejected; at least 18 were expected.',
        );
        $ledger->check(
            'Import C still found valid rows among the invalid ones',
            $import->valid_rows > 0,
            'No valid rows survived workbook C.',
        );
        $ledger->check(
            'Import C matched its duplicates of A',
            $import->duplicate_rows >= 5,
            'Expected at least 5 duplicates, found '.$import->duplicate_rows.'.',
        );

        $ledger->same(
            'Staging C wrote nothing into the question bank',
            $questionsBefore,
            $fellowship->questions()->count(),
        );

        $this->assertInvalidRowReasons($context, $import);

        // Decide the duplicates, then confirm and check only the valid rows went in.
        $this->decideAllPending($component, $import, FellowshipImportRowDecision::Skip);

        $this->wizard->confirm($component);

        $import->refresh();
        $fellowship->refresh();

        $ledger->same('Import C completed', FellowshipImportStatus::Completed, $import->status);
        $ledger->same(
            'Import C inserted only its valid new rows',
            $questionsBefore + $import->created_questions,
            $fellowship->questions()->count(),
        );

        $invalidWithQuestion = $import->rows()
            ->where('status', FellowshipImportRowStatus::Invalid)
            ->whereNotNull('fellowship_question_id')
            ->count();

        $ledger->same('No invalid row produced a question', 0, $invalidWithQuestion);

        $invalidProcessed = $import->rows()
            ->where('status', FellowshipImportRowStatus::Invalid)
            ->whereNotNull('processed_at')
            ->count();

        $ledger->same('Invalid rows were never acted on at all', 0, $invalidProcessed);

        $skippedWithQuestion = $import->rows()
            ->where('outcome', FellowshipImportRowOutcome::Skipped)
            ->whereNotNull('fellowship_question_id')
            ->count();

        $ledger->same('A skipped duplicate produced no question of its own', 0, $skippedWithQuestion);

        $this->assertQuestionShape($context, 'Import C');
    }

    /**
     * Each invalid row must carry a reason that actually names its problem, and
     * must be reported against its own spreadsheet row number.
     */
    private function assertInvalidRowReasons(FellowshipSmokeContext $context, FellowshipImport $import): void
    {
        $ledger = $context->ledger;

        $invalid = $import->rows()->where('status', FellowshipImportRowStatus::Invalid)->get();

        $ledger->check(
            'Every invalid row carries at least one reason',
            $invalid->every(fn (FellowshipImportRow $row): bool => is_array($row->errors) && $row->errors !== []),
            'Rows without a reason: '.$invalid->filter(fn ($r): bool => empty($r->errors))->pluck('row_number')->implode(', '),
        );

        $allReasons = $invalid->flatMap(fn (FellowshipImportRow $row): array => $row->errors ?? [])
            ->map(fn (string $reason): string => mb_strtolower($reason))
            ->all();

        $expected = [
            'empty question text' => 'question text is empty',
            'too few options' => 'at least two non-empty answer options',
            'empty correct answer' => 'correct answer is empty',
            'unmatched correct answer' => 'correct answer does not match',
            'identical options' => 'two answer options are identical',
            'bad difficulty' => 'difficulty must be one of',
            'empty difficulty' => 'difficulty is empty',
            'missing topic' => 'performance topic is empty',
        ];

        foreach ($expected as $label => $needle) {
            $ledger->check(
                'Import C reports "'.$label.'"',
                collect($allReasons)->contains(fn (string $reason): bool => str_contains($reason, $needle)),
                'No staged row gave a reason containing "'.$needle.'".',
            );
        }

        // Row numbers must be real spreadsheet positions within the file.
        $maxRow = (int) $import->rows()->max('row_number');
        $minRow = (int) $import->rows()->min('row_number');

        // The last readable line sits one row below the last line written,
        // because the blank line in between was skipped, not renumbered.
        $ledger->check(
            'Invalid rows are reported against real spreadsheet row numbers',
            $minRow === 2 && $maxRow <= $import->total_rows + 2,
            sprintf('Row numbers ran %d..%d for %d readable rows.', $minRow, $maxRow, $import->total_rows),
        );

        $ledger->check(
            'No two staged rows claim the same spreadsheet row number',
            $import->rows()->distinct()->count('row_number') === $import->total_rows,
            'Row numbers were not unique across the staged rows.',
        );

        // One specific row, checked precisely: the ambiguous letter-versus-text
        // row must be rejected rather than guessed.
        $ambiguous = $invalid->first(
            fn (FellowshipImportRow $row): bool => str_contains((string) ($row->payload['question_text'] ?? ''), 'labelled A in this stem'),
        );

        $ledger->check(
            'An answer that reads both as a label and as an option text is refused',
            $ambiguous !== null,
            'The ambiguous correct-answer row was not rejected.',
        );
    }

    // ── Step 7: retry safety ──────────────────────────────────────────────────

    /**
     * Confirming twice must not insert twice, and an import interrupted part way
     * must be resumable rather than repeated.
     */
    private function confirmRetryIsSafe(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Retry safety');

        $fellowship = $context->fellowship;
        $importId = $context->imports['A'] ?? null;

        if ($fellowship === null || $importId === null) {
            $ledger->skip('Repeated confirmation is safe', 'No completed import to re-confirm.');

            return;
        }

        $import = FellowshipImport::query()->find($importId);

        if ($import === null) {
            $ledger->skip('Repeated confirmation is safe', 'Import A is gone.');

            return;
        }

        $questionsBefore = $fellowship->questions()->count();
        $createdBefore = $import->created_questions;

        // Through the service, which is where the claim lives.
        $ledger->attempt('Re-confirming a completed import changes nothing', function () use ($import): void {
            app(FellowshipImportConfirmationService::class)->confirm($import);
        });

        $import->refresh();

        $ledger->same('A re-confirmed import stays completed', FellowshipImportStatus::Completed, $import->status);
        $ledger->same('A re-confirmed import inserts nothing further', $questionsBefore, $fellowship->questions()->count());
        $ledger->same('A re-confirmed import keeps its counts', $createdBefore, $import->created_questions);

        // Through the page, which must also refuse.
        $component = $ledger->attempt(
            'The page refuses to confirm a completed import',
            fn () => $this->wizard->reopen($fellowship, $importId),
        );

        if ($component !== null) {
            $this->wizard->confirm($component);
            $ledger->same(
                'Confirming a completed import from the page inserts nothing',
                $questionsBefore,
                $fellowship->questions()->count(),
            );
        }

        $this->assertInterruptedConfirmationResumes($context);
    }

    /**
     * A confirmation that stopped part way: the rows already stamped stay
     * stamped, and a retry finishes the rest rather than redoing them.
     */
    private function assertInterruptedConfirmationResumes(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->otherFellowship;
        $workbook = $context->workbooks['D1'] ?? null;

        if ($fellowship === null || $workbook === null) {
            $ledger->skip('An interrupted confirmation resumes', 'No Fellowship or workbook for the partial run.');

            return;
        }

        // A fresh import of the same file, so the rows are all duplicates we can
        // decide to Keep Both — giving a file with several inserts to interrupt.
        $component = $ledger->attempt(
            'Partial-run import staged',
            fn () => $this->wizard->uploadAndRead($this->wizard->open($fellowship), $workbook['path']),
        );

        if ($component === null) {
            return;
        }

        $this->wizard->map($component, [
            'question_text' => 'Question Text',
            'correct_answer' => 'Correct Answer',
            'difficulty' => 'Difficulty',
            'performance_topic' => 'Exam',
            'option_1' => 'True',
            'option_2' => 'False',
        ]);

        $this->wizard->stage($component);

        $importId = (int) $component->get('import');
        $import = FellowshipImport::query()->find($importId);

        if ($import === null) {
            $ledger->fail('Partial-run import staged', 'No import record.');

            return;
        }

        $this->decideAllPending($component, $import, FellowshipImportRowDecision::KeepBoth);

        $rows = $import->rows()->importable()->orderBy('row_number')->get();

        if ($rows->count() < 2) {
            $ledger->skip('An interrupted confirmation resumes', 'Too few rows to interrupt.');

            return;
        }

        // Stand in for "already applied": stamp the first row as skipped, as an
        // interrupted run would have left it.
        $first = $rows->first();
        $first->update([
            'outcome' => FellowshipImportRowOutcome::Skipped,
            'processed_at' => now(),
        ]);

        $questionsBefore = $fellowship->questions()->count();
        $remaining = $rows->count() - 1;

        $ledger->attempt('Interrupted confirmation resumed', function () use ($import): void {
            app(FellowshipImportConfirmationService::class)->confirm($import);
        });

        $import->refresh();

        $ledger->same('The resumed import completed', FellowshipImportStatus::Completed, $import->status);
        $ledger->same(
            'The resumed import applied only the rows still pending',
            $questionsBefore + $remaining,
            $fellowship->questions()->count(),
        );
        $ledger->same(
            'The already-stamped row kept its outcome',
            FellowshipImportRowOutcome::Skipped,
            $first->refresh()->outcome,
        );

        $context->imports['D1-partial'] = $importId;
    }

    /**
     * Every string the Fellowship admin screens use must exist in both locales.
     *
     * The panel is bilingual and its default here is Arabic, so a key present in
     * one file and missing from the other renders as a raw key to half the
     * admins. This checks the key sets agree and that neither side left a value
     * empty; whether the Arabic actually lays out right to left is a question
     * for the browser, not for this run.
     */
    private function assertTranslationsAreComplete(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Admin · Localisation');

        $load = static function (string $locale): array {
            $path = resource_path('lang/'.$locale.'/admin.php');

            if (! is_file($path)) {
                return [];
            }

            /** @var array<string, mixed> $translations */
            $translations = require $path;

            return is_array($translations['fellowship'] ?? null) ? $translations['fellowship'] : [];
        };

        $english = $load('en');
        $arabic = $load('ar');

        $ledger->check('English Fellowship strings are present', $english !== [], 'resources/lang/en/admin.php has no fellowship group.');
        $ledger->check('Arabic Fellowship strings are present', $arabic !== [], 'resources/lang/ar/admin.php has no fellowship group.');

        $missingInArabic = array_values(array_diff(array_keys($english), array_keys($arabic)));
        $missingInEnglish = array_values(array_diff(array_keys($arabic), array_keys($english)));

        $ledger->same('No Fellowship string is missing its Arabic translation', [], $missingInArabic);
        $ledger->same('No Fellowship string is missing its English translation', [], $missingInEnglish);

        $empty = [];

        foreach (['en' => $english, 'ar' => $arabic] as $locale => $strings) {
            foreach ($strings as $key => $value) {
                if (is_string($value) && trim($value) === '') {
                    $empty[] = $locale.'.'.$key;
                }
            }
        }

        $ledger->same('No Fellowship string is left empty in either locale', [], $empty);

        // The wizard's own strings resolve in both locales rather than falling
        // through as raw keys.
        $wizardKeys = [
            'import_step_upload',
            'import_section_system_fields',
            'import_section_extra_columns',
            'import_column_unmapped',
            'import_upload_missing',
            'import_duplicates_undecided_title',
            'import_completed_title',
            'decision_pending',
            'decision_skip',
            'decision_update',
            'decision_keep_both',
        ];

        foreach (['en', 'ar'] as $locale) {
            $unresolved = [];

            foreach ($wizardKeys as $key) {
                $resolved = trans('admin.fellowship.'.$key, [], $locale);

                if ($resolved === 'admin.fellowship.'.$key || trim((string) $resolved) === '') {
                    $unresolved[] = $key;
                }
            }

            $ledger->same('Every import wizard string resolves in '.$locale, [], $unresolved);
        }

        $ledger->note('Fellowship strings per locale', count($english).' en / '.count($arabic).' ar');
    }

    /**
     * Request an admin-panel URL as one admin, and report the status.
     *
     * Goes through the HTTP kernel so the panel's own middleware — the admin
     * guard and the page's authorization — decides the outcome. Used only for
     * the refusal: a page that renders successfully needs built frontend assets,
     * which a console run has no business depending on, so the allowed case is
     * driven through Livewire instead.
     */
    private function requestAsAdmin(Admin $admin, string $url): int
    {
        $previous = Auth::guard('admin')->user();

        Auth::guard('admin')->login($admin);

        try {
            $request = Request::create(
                $url,
                'GET',
                server: ['HTTP_ACCEPT' => 'text/html', 'REMOTE_ADDR' => '127.0.0.1'],
            );

            $request->setLaravelSession(app('session.store'));

            return app(HttpKernel::class)->handle($request)->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        } catch (\Throwable) {
            return 0;
        } finally {
            if ($previous !== null) {
                Auth::guard('admin')->login($previous);
            }
        }
    }

    /**
     * Give every still-pending duplicate the same decision, through the page, so
     * confirmation is not blocked by rows a scenario does not care about.
     */
    private function decideAllPending(
        Testable $component,
        FellowshipImport $import,
        FellowshipImportRowDecision $decision,
    ): void {
        foreach ($import->rowsAwaitingDecision()->get() as $row) {
            $chosen = $row->allows($decision)
                ? $decision
                : FellowshipImportRowDecision::Skip;

            $this->wizard->decide($component, (int) $row->getKey(), $chosen->value);
        }
    }
}
