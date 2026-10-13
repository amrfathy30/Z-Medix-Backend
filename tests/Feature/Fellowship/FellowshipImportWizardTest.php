<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\DuplicateComparison;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The import wizard itself: that the Fellowship comes from the record rather
 * than from a field, that the steps lead one into the next, and that
 * Confirmation is what writes.
 */
class FellowshipImportWizardTest extends FellowshipTestCase
{
    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'Key', 'Level', 'Area', 'System'];
    }

    /** @return list<list<string|null>> */
    private function rows(): array
    {
        return [
            ['Which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology', 'Cardiovascular'],
            ['Which anticoagulant?', 'Aspirin', 'Warfarin', 'Warfarin', 'Hard', 'Cardiology', 'Cardiovascular'],
        ];
    }

    /**
     * Walk the wizard through upload and mapping, leaving it on review.
     */
    private function wizardOnReview(Fellowship $fellowship, ?array $rows = null)
    {
        $component = $this->wizardOnMapping($fellowship, $rows);

        $this->mapWizard($component, $this->systemFieldMapping(), [
            // The one column no system field takes becomes a new filter.
            6 => [
                'target' => FellowshipImportMappingTarget::NewFilter->value,
                'filter_id' => null,
                'filter_name' => 'System',
            ],
        ]);

        return $component->call('submitMapping');
    }

    /**
     * Upload the file and stop on the mapping step.
     */
    private function wizardOnMapping(Fellowship $fellowship, ?array $rows = null)
    {
        $path = $this->uploadCsv($this->headers(), $rows ?? $this->rows(), Str::uuid().'.csv');

        return Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            // Filament's file upload state is keyed by a generated id.
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);
    }

    /**
     * Which column of the file fills each system field.
     *
     * @return array<string, string>
     */
    private function systemFieldMapping(): array
    {
        return [
            'question_text' => 'Stem',
            'option_1' => 'A',
            'option_2' => 'B',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    public function test_the_wizard_opens_on_the_upload_step(): void
    {
        $fellowship = $this->fellowship();

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->assertSuccessful()
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);
    }

    public function test_uploading_reads_the_files_own_column_names(): void
    {
        $fellowship = $this->fellowship();
        $path = $this->uploadCsv($this->headers(), $this->rows());

        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            // Filament's file upload state is keyed by a generated id.
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $import = $fellowship->imports()->sole();

        $this->assertSame($this->headers(), $import->headers);
        $this->assertSame(FellowshipImportStatus::Draft, $import->status);
        $this->assertSame($component->get('import'), $import->getKey());
    }

    public function test_the_import_belongs_to_the_fellowship_the_wizard_was_opened_on(): void
    {
        $fellowship = $this->fellowship('Emergency Medicine');
        $other = $this->fellowship('Internal Medicine');

        $this->wizardOnReview($fellowship)->call('confirm');

        $this->assertSame(2, $fellowship->questions()->count());
        $this->assertSame(0, $other->questions()->count());
        $this->assertSame($fellowship->getKey(), $fellowship->imports()->sole()->fellowship_id);
    }

    public function test_staging_reports_what_the_file_contains_without_writing_anything(): void
    {
        $fellowship = $this->fellowship();

        $this->wizardOnReview($fellowship)
            ->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $import = $fellowship->imports()->sole();

        $this->assertSame(FellowshipImportStatus::Staged, $import->status);
        $this->assertSame(2, $import->valid_rows);
        $this->assertSame(0, $fellowship->questions()->count(), 'Staging writes no questions.');
        $this->assertSame(1, $fellowship->filters()->count(), 'Staging publishes no filters.');
    }

    public function test_confirming_writes_the_questions_and_the_new_filter(): void
    {
        $fellowship = $this->fellowship();

        $this->wizardOnReview($fellowship)->call('confirm');

        $import = $fellowship->imports()->sole();

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->created_questions);
        $this->assertSame(2, $fellowship->questions()->count());
        $this->assertSame('System', $fellowship->dynamicFilters()->sole()->name);
    }

    public function test_an_incomplete_mapping_will_not_stage(): void
    {
        $fellowship = $this->fellowship();

        $component = $this->wizardOnMapping($fellowship);

        // Only the question text is given a column; everything else is left.
        $this->mapWizard($component, ['question_text' => 'Stem']);

        $component->call('submitMapping')
            ->assertHasErrors()
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $this->assertSame(FellowshipImportStatus::Draft, $fellowship->imports()->sole()->status);
        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_confirming_is_refused_while_a_required_mapping_is_missing(): void
    {
        $fellowship = $this->fellowship();

        $component = $this->wizardOnReview($fellowship);

        // Blank out the stored mapping, as a tampered or stale submission would.
        $import = $fellowship->imports()->sole();
        $import->update(['header_map' => []]);

        $component->call('confirm');

        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertSame(FellowshipImportStatus::Staged, $import->refresh()->status);
    }

    public function test_duplicate_decisions_are_recorded_as_the_admin_makes_them(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which antiplatelet?');

        $component = $this->wizardOnReview($fellowship);

        $row = $fellowship->imports()->sole()->duplicateRows()->sole();

        $component
            // Pending: no decision is made on the admin's behalf.
            ->assertSet("decisions.{$row->id}", '')
            ->set("decisions.{$row->id}", FellowshipImportRowDecision::KeepBoth->value);

        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $row->refresh()->decision);

        $component->call('confirm');

        $this->assertSame(3, $fellowship->questions()->count());
    }

    public function test_a_decision_a_row_cannot_take_is_refused(): void
    {
        $fellowship = $this->fellowship();

        // Two identical rows, so the second duplicates the first within the
        // file and has no stored question to update.
        $component = $this->wizardOnReview($fellowship, [
            ['Which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology', 'Cardiovascular'],
            ['which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology', 'Cardiovascular'],
        ]);

        $row = $fellowship->imports()->sole()->duplicateRows()->sole();

        $component->set("decisions.{$row->id}", FellowshipImportRowDecision::UpdateExisting->value);

        $this->assertNull($row->refresh()->decision, 'An unavailable decision must not be stored.');
    }

    public function test_the_wizard_offers_the_side_by_side_for_a_duplicate(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, topic: 'Cardiology', text: 'Which antiplatelet?', options: 2);

        $this->wizardOnReview($fellowship);

        $page = new ImportFellowshipQuestions;
        $import = $fellowship->imports()->sole();
        $row = $import->duplicateRows()->sole();
        $row->setRelation('import', $import);

        $comparison = DuplicateComparison::build($row, $existing);

        $this->assertTrue($comparison->hasConflicts(), 'The option sets differ.');
        $this->assertTrue($comparison->optionsConflict);
        $this->assertNotSame([], $comparison->fields);

        $questionField = collect($comparison->fields)->firstWhere('label', 'Question text');
        $this->assertSame('Which antiplatelet?', $questionField['existing']);
        $this->assertSame('Which antiplatelet?', $questionField['imported']);
        $this->assertFalse($questionField['conflicts']);
    }

    public function test_a_staged_import_is_resumed_on_the_review_step(): void
    {
        $fellowship = $this->fellowship();

        $this->wizardOnReview($fellowship);

        $import = $fellowship->imports()->sole();

        Livewire::test(ImportFellowshipQuestions::class, [
            'record' => $fellowship->getKey(),
            'import' => $import->getKey(),
        ])
            ->assertSuccessful()
            ->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);
    }

    public function test_confirming_twice_from_the_wizard_does_not_import_twice(): void
    {
        $fellowship = $this->fellowship();

        $component = $this->wizardOnReview($fellowship);

        $component->call('confirm');
        $component->call('confirm');

        $this->assertSame(2, $fellowship->questions()->count());
    }

    public function test_an_admin_without_the_create_permission_cannot_reach_the_wizard(): void
    {
        $this->actingAs($this->adminWithPermissions(['fellowships.view', 'fellowship_questions.view']), 'admin');

        $this->assertFalse(ImportFellowshipQuestions::canImport());
        $this->assertFalse(ImportFellowshipQuestions::canAccess(['record' => $this->fellowship()->getKey()]));
    }

    public function test_the_import_records_who_ran_it(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');

        $fellowship = $this->fellowship();
        $this->wizardOnReview($fellowship);

        $this->assertSame($admin->getKey(), $fellowship->imports()->sole()->admin_id);
    }

    /**
     * The upload as the admin performs it: the file is still a pending Livewire
     * upload when Read Columns is pressed, not a path already on the disk.
     */
    public function test_reading_the_columns_of_a_pending_upload_moves_on_to_mapping(): void
    {
        $fellowship = $this->fellowship();

        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [$this->xlsxUpload($this->headers(), $this->rows())])
            ->call('submitUpload')
            ->assertHasNoErrors()
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $import = $fellowship->imports()->sole();

        $this->assertSame($this->headers(), $import->headers);
        $this->assertSame($component->get('import'), $import->getKey());
        $this->assertTrue(
            Storage::disk('local')->exists($import->stored_path),
            'The pending upload must be moved onto the import disk.',
        );
        $this->assertStringEndsWith('.xlsx', $import->stored_path);
    }

    /**
     * A temporary upload that has expired or been swept leaves a path behind
     * that no longer resolves; the wizard says so on the field instead of
     * moving on to a mapping it cannot build.
     */
    public function test_an_expired_temporary_upload_is_reported_on_the_field(): void
    {
        $fellowship = $this->fellowship();

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => 'fellowship-imports/gone.xlsx'])
            ->call('submitUpload')
            ->assertHasErrors('uploadData.file')
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);

        $this->assertSame(0, $fellowship->imports()->count());
    }

    public function test_an_import_whose_upload_has_gone_cannot_be_staged(): void
    {
        $fellowship = $this->fellowship();

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'missing.csv',
            'stored_path' => 'fellowship-imports/missing.csv',
            'headers' => $this->headers(),
        ]);

        $this->expectException(FellowshipImportException::class);

        app(FellowshipImportStagingService::class)
            ->stage($import, ImportHeaderMap::fromArray([
                'Stem' => FellowshipImportMappingTarget::QuestionText->value,
                'A' => FellowshipImportMappingTarget::AnswerOption->value,
                'B' => FellowshipImportMappingTarget::AnswerOption->value,
                'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
                'Level' => FellowshipImportMappingTarget::Difficulty->value,
                'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            ]));
    }
}
