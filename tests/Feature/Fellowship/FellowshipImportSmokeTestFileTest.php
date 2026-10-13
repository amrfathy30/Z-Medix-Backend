<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipFilterService;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipQuestionWriter;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;

/**
 * The real import file, start to finish.
 *
 * Both failures this covers were found by importing it and neither could be
 * found any other way:
 *
 *  - every one of its ninety rows was rejected, because its Correct Answer
 *    column holds a letter and the importer only compared answer *text*;
 *  - confirming it then failed with a SQL error, because the outcome tally was
 *    aggregated over a relation that carries an ORDER BY, which MySQL refuses
 *    under ONLY_FULL_GROUP_BY — and the recovery that was supposed to put the
 *    import back for a retry left it stranded mid-flight instead.
 *
 * The file is a fixture, so the first of those is a real end-to-end assertion
 * rather than a reconstruction.
 */
class FellowshipImportSmokeTestFileTest extends FellowshipTestCase
{
    /**
     * The mapping the admin made in the smoke test.
     *
     * @return array<string, string>
     */
    private function systemFieldMapping(): array
    {
        return [
            'question_text' => 'Question',
            'option_1' => 'Option A',
            'option_2' => 'Option B',
            'option_3' => 'Option C',
            'option_4' => 'Option D',
            'correct_answer' => 'Correct Answer',
            'difficulty' => 'Difficulty',
            'performance_topic' => 'System',
            'explanation' => 'Explanation',
        ];
    }

    /**
     * Exam, Year and Source are the columns no system field takes; each becomes
     * a dynamic filter of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extraColumns(): array
    {
        $rows = [];

        foreach ([8 => 'Exam', 10 => 'Year', 11 => 'Source'] as $index => $name) {
            $rows[$index] = [
                'target' => FellowshipImportMappingTarget::NewFilter->value,
                'filter_id' => null,
                'filter_name' => $name,
            ];
        }

        return $rows;
    }

    /**
     * Upload the real workbook, map it, and stage it.
     */
    private function stagedWizard(Fellowship $fellowship)
    {
        $path = $this->storedSmokeTestWorkbook(Str::uuid().'.xlsx');

        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $this->mapWizard($component, $this->systemFieldMapping(), $this->extraColumns());

        return $component->call('submitMapping')
            ->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    public function test_the_file_is_read_with_its_own_twelve_columns(): void
    {
        $path = $this->storedSmokeTestWorkbook();

        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $this->fellowship()->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload');

        $this->assertSame(
            ['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Answer',
                'Difficulty', 'Explanation', 'Exam', 'System', 'Year', 'Source'],
            $component->instance()->headers(),
        );
    }

    /**
     * Issue 1, as the smoke test hit it: ninety rows, all of them importable.
     */
    public function test_every_row_of_the_file_stages_as_valid(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship);

        $import = $fellowship->imports()->sole();

        $this->assertSame(90, $import->total_rows);
        $this->assertSame(90, $import->valid_rows, 'A letter answer must not make a row invalid.');
        $this->assertSame(0, $import->invalid_rows);
        $this->assertSame(0, $import->duplicate_rows);
        $this->assertSame(FellowshipImportStatus::Staged, $import->status);

        // Staging writes nothing into the Fellowship itself: no questions, and
        // none of the three filters the mapping asked for.
        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertSame(0, $fellowship->dynamicFilters()->count());
    }

    /**
     * Issue 2: Confirm, which is where the SQL error struck.
     */
    public function test_confirming_the_file_imports_every_question(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship)->call('confirm');

        $import = $fellowship->imports()->sole();

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertNull($import->failure_reason);
        $this->assertSame(90, $import->created_questions);
        $this->assertSame(0, $import->skipped_questions);
        $this->assertSame(90, $fellowship->questions()->count());

        // The three left-over columns, plus the Performance Topic.
        $this->assertSame(
            ['Exam', 'Source', 'Year'],
            $fellowship->dynamicFilters()->pluck('name')->sort()->values()->all(),
        );
    }

    /**
     * The letters actually landed on the right answers — the point of the whole
     * fix. Row 2 of the file answers "A", row 3 answers "D", row 4 "C".
     */
    public function test_the_imported_questions_have_the_answer_the_file_named(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship)->call('confirm');

        $expected = [
            'Which ECG finding is most characteristic of atrial fibrillation?' => 'Irregularly irregular rhythm',
            'Which blood test is used to monitor warfarin therapy?' => 'INR',
            'Which cardiac biomarker is commonly used to diagnose myocardial injury?' => 'Troponin',
            'Which condition is classically associated with a pansystolic murmur at the apex?' => 'Mitral regurgitation',
        ];

        foreach ($expected as $questionText => $correctOption) {
            /** @var FellowshipQuestion|null $question */
            $question = $fellowship->questions()->where('question_text', $questionText)->first();

            $this->assertNotNull($question, "[{$questionText}] was not imported.");

            $correct = $question->options()->where('is_correct', true)->get();

            $this->assertCount(1, $correct, 'A question carries exactly one correct option.');
            $this->assertSame($correctOption, $correct->first()->option_text);
            $this->assertSame(4, $question->options()->count());
        }
    }

    /**
     * The failing statement itself, as `refreshOutcomeCounts()` issues it.
     *
     * MySQL under ONLY_FULL_GROUP_BY rejects ordering by a column that is
     * neither grouped nor aggregated, and `rows()` orders by row number. SQLite
     * does not enforce that, so the statement is captured and inspected: a
     * behavioural assertion on this suite's database would pass either way and
     * catch nothing.
     */
    public function test_the_outcome_tally_is_aggregated_without_an_order_by(): void
    {
        $fellowship = $this->fellowship();
        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Staged,
            'original_filename' => 'smoke.xlsx',
            'stored_path' => 'fellowship-imports/smoke.xlsx',
            'headers' => ['Question'],
        ]);

        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $import->refreshOutcomeCounts();

        $aggregates = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains(strtolower($sql), 'group by'),
        ));

        $this->assertCount(1, $aggregates, 'The tally is one grouped query.');
        $this->assertStringNotContainsStringIgnoringCase(
            'order by',
            $aggregates[0],
            'An aggregate over outcomes must not carry the row ordering.',
        );

        // The relation really does order by default, so dropping that ordering
        // is load-bearing rather than decorative.
        $this->assertStringContainsStringIgnoringCase('order by', $import->rows()->toSql());
    }

    public function test_the_outcome_tally_counts_what_actually_happened(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship)->call('confirm');

        $import = $fellowship->imports()->sole();

        $import->refreshOutcomeCounts();

        $this->assertSame(90, $import->refresh()->created_questions);
        $this->assertSame(
            90,
            $import->rows()->where('outcome', FellowshipImportRowOutcome::Created)->count(),
        );
    }

    /**
     * A confirmation that fails for a reason the module does not model must
     * leave the import where a retry can pick it up.
     *
     * This is what the smoke test lost: the status was written through a
     * separately loaded row while the recovery wrote through a stale instance,
     * so the reset was not a change Eloquent sent, and the import sat in
     * `processing` — which no later Confirm can claim.
     */
    public function test_a_failed_confirmation_leaves_the_import_ready_to_retry(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship);

        $import = $fellowship->imports()->sole();

        // A real import whose outcome tally fails — the statement that actually
        // broke. Eloquent names a model's table and foreign key after its
        // class, so an anonymous subclass has to say what it stands in for, and
        // it builds instances of the class itself, so the import is optional.
        $broken = new class($import) extends FellowshipImport
        {
            protected $table = 'fellowship_imports';

            public function __construct(?FellowshipImport $import = null)
            {
                parent::__construct();

                if ($import !== null) {
                    $this->setRawAttributes($import->getAttributes(), sync: true);
                    $this->exists = true;
                }
            }

            public function getForeignKey(): string
            {
                return 'fellowship_import_id';
            }

            public function refreshOutcomeCounts(): void
            {
                throw new RuntimeException('something the module does not model');
            }
        };

        try {
            app(FellowshipImportConfirmationService::class)->confirm($broken);
            $this->fail('A failed confirmation must report itself.');
        } catch (FellowshipImportException $exception) {
            // Localized and understandable, not a fragment of SQL.
            $this->assertSame(__('admin.fellowship.import_error_confirmation_failed'), $exception->getMessage());
        }

        $stored = $import->fresh();

        $this->assertSame(
            FellowshipImportStatus::Staged,
            $stored->status,
            'A stranded import can never be claimed again.',
        );
        $this->assertNotNull($stored->failure_reason, 'The real reason is kept for diagnosis.');
        $this->assertStringContainsString('does not model', (string) $stored->failure_reason);

        // And it really can be retried, from the rows already staged.
        app(FellowshipImportConfirmationService::class)->confirm($stored);

        $this->assertSame(FellowshipImportStatus::Completed, $import->fresh()->status);
        $this->assertSame(90, $fellowship->questions()->count());
    }

    /**
     * One row failing unexpectedly is that row's problem: it is recorded as
     * failed, nothing of it is written, and the rest of the file still imports.
     */
    public function test_a_row_that_fails_unexpectedly_does_not_abort_the_import(): void
    {
        $fellowship = $this->fellowship();

        $this->stagedWizard($fellowship);

        // One row's payload is left unwritable: a performance topic no filter
        // can satisfy stands in for any unforeseen write failure.
        $row = $fellowship->imports()->sole()->rows()->orderBy('row_number')->first();
        $payload = $row->payload();

        $this->app->bind(FellowshipQuestionWriter::class, function ($app) use ($payload) {
            return new class($app->make(FellowshipFilterService::class), $payload) extends FellowshipQuestionWriter
            {
                public function __construct(FellowshipFilterService $filters, private ImportRowPayload $doomed)
                {
                    parent::__construct($filters);
                }

                public function create(Fellowship $fellowship, ImportRowPayload $payload, ImportHeaderMap $map): FellowshipQuestion
                {
                    if ($payload->questionText === $this->doomed->questionText) {
                        throw new RuntimeException('a constraint the module does not model');
                    }

                    return parent::create($fellowship, $payload, $map);
                }
            };
        });

        app(FellowshipImportConfirmationService::class)->confirm($fellowship->imports()->sole());

        $import = $fellowship->imports()->sole();

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertSame(89, $fellowship->questions()->count(), 'The other rows still import.');
        $this->assertSame(89, $import->created_questions);

        $failed = $import->rows()->where('outcome', FellowshipImportRowOutcome::Failed)->sole();

        $this->assertSame($row->getKey(), $failed->getKey());
        $this->assertNull($failed->fellowship_question_id, 'A failed row writes no question.');
        $this->assertSame(
            0,
            $fellowship->questions()->where('question_text', $payload->questionText)->count(),
            'Nothing of the failed row was written.',
        );
    }

    /**
     * The admin is never shown the database's words.
     */
    public function test_the_failure_the_admin_is_shown_is_localized(): void
    {
        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            foreach (['import_error_confirmation_failed', 'import_error_row_failed'] as $key) {
                $this->assertNotSame(
                    'admin.fellowship.'.$key,
                    __('admin.fellowship.'.$key),
                    "[{$key}] is missing from the [{$locale}] translations.",
                );
            }

            $this->assertStringNotContainsStringIgnoringCase(
                'sqlstate',
                FellowshipImportException::confirmationFailed()->getMessage(),
            );
        }
    }
}
