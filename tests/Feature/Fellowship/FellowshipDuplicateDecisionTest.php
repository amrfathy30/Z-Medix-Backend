<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\DuplicateComparison;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Deciding duplicates: individually, in bulk, and the rule that nothing
 * happens to a duplicate until the admin says so.
 *
 * Covered at both levels on purpose. The review screen is where the admin
 * works, but the service refuses an unresolved import independently of it, so
 * the rule cannot be bypassed by a stale page or a scripted request.
 */
class FellowshipDuplicateDecisionTest extends FellowshipTestCase
{
    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'Key', 'Level', 'Area'];
    }

    private function map(): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);
    }

    /** @return list<string|null> */
    private function row(string $stem, string $key = 'Aspirin', string $a = 'Aspirin', string $b = 'Warfarin'): array
    {
        return [$stem, $a, $b, $key, 'Easy', 'Cardiology'];
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function stage(Fellowship $fellowship, array $rows): FellowshipImport
    {
        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'questions.csv',
            'stored_path' => $this->uploadCsv($this->headers(), $rows, Str::uuid().'.csv'),
            'headers' => $this->headers(),
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $this->map());
    }

    /**
     * A fellowship with three stored questions, and a file repeating all three
     * — so every row is a duplicate of an existing question.
     *
     * @return array{0: Fellowship, 1: FellowshipImport}
     */
    private function threeDuplicates(): array
    {
        $fellowship = $this->fellowship();

        foreach (['First?', 'Second?', 'Third?'] as $text) {
            $this->question($fellowship, text: $text);
        }

        return [$fellowship, $this->stage($fellowship, [
            $this->row('First?'),
            $this->row('Second?'),
            $this->row('Third?'),
        ])];
    }

    private function service(): FellowshipImportConfirmationService
    {
        return app(FellowshipImportConfirmationService::class);
    }

    // ---------------------------------------------------------------- service

    public function test_each_of_the_three_decisions_is_available_for_an_existing_duplicate(): void
    {
        [, $import] = $this->threeDuplicates();

        $this->assertEqualsCanonicalizing(
            [
                FellowshipImportRowDecision::Skip,
                FellowshipImportRowDecision::UpdateExisting,
                FellowshipImportRowDecision::KeepBoth,
            ],
            $import->duplicateRows()->first()->availableDecisions(),
        );
    }

    public function test_the_three_decisions_can_be_applied_one_row_at_a_time(): void
    {
        [$fellowship, $import] = $this->threeDuplicates();

        $rows = $import->duplicateRows()->get();

        $this->service()->decide($rows[0], FellowshipImportRowDecision::Skip);
        $this->service()->decide($rows[1], FellowshipImportRowDecision::UpdateExisting);
        $this->service()->decide($rows[2], FellowshipImportRowDecision::KeepBoth);

        $import = $this->service()->confirm($import);

        $this->assertSame(1, $import->skipped_questions);
        $this->assertSame(1, $import->updated_questions);
        $this->assertSame(1, $import->created_questions);
        $this->assertSame(4, $fellowship->questions()->count());
    }

    public function test_a_bulk_decision_applies_to_the_selected_rows_only(): void
    {
        [$fellowship, $import] = $this->threeDuplicates();

        $rows = $import->duplicateRows()->get();

        $result = $this->service()->decideMany(
            [$rows[0], $rows[1]],
            FellowshipImportRowDecision::KeepBoth,
        );

        $this->assertSame(['applied' => 2, 'skipped' => 0], $result);
        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $rows[0]->refresh()->decision);
        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $rows[1]->refresh()->decision);
        $this->assertNull($rows[2]->refresh()->decision, 'An unselected row is untouched.');
        $this->assertSame(1, $import->refresh()->unresolvedDuplicatesCount());
    }

    public function test_a_bulk_decision_leaves_rows_that_cannot_take_it_awaiting_a_decision(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        // One duplicate of a stored question, and one repeated within the file
        // — which has nothing stored to update.
        $import = $this->stage($fellowship, [
            $this->row('First?'),
            $this->row('Second?'),
            $this->row('second?'),
        ]);

        $rows = $import->duplicateRows()->get();
        $this->assertCount(2, $rows);

        $result = $this->service()->decideMany($rows, FellowshipImportRowDecision::UpdateExisting);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(1, $result['skipped'], 'The within-file duplicate cannot be updated.');

        $withinFile = $rows->first(fn (FellowshipImportRow $row): bool => $row->duplicate_of_row_number !== null);

        $this->assertNull($withinFile->refresh()->decision);
        $this->assertTrue($withinFile->awaitsDecision());
        $this->assertSame(1, $import->refresh()->unresolvedDuplicatesCount());
    }

    public function test_confirmation_is_refused_while_any_duplicate_awaits_a_decision(): void
    {
        [$fellowship, $import] = $this->threeDuplicates();

        $rows = $import->duplicateRows()->get();
        $this->service()->decide($rows[0], FellowshipImportRowDecision::Skip);
        $this->service()->decide($rows[1], FellowshipImportRowDecision::Skip);

        try {
            $this->service()->confirm($import);
            $this->fail('Confirmation must be refused.');
        } catch (FellowshipImportException $exception) {
            $this->assertStringContainsString('One duplicate row', $exception->getMessage());
        }

        $this->assertSame(3, $fellowship->questions()->count());
        $this->assertSame(FellowshipImportStatus::Staged, $import->refresh()->status);
        $this->assertSame(0, $import->processedRows()->count());
    }

    public function test_an_undecided_duplicate_is_never_skipped_updated_or_inserted(): void
    {
        [$fellowship, $import] = $this->threeDuplicates();

        $before = $fellowship->questions()->get()->map->only(['id', 'question_text', 'difficulty'])->toArray();

        try {
            $this->service()->confirm($import);
        } catch (FellowshipImportException) {
            // Expected.
        }

        $after = $fellowship->questions()->get()->map->only(['id', 'question_text', 'difficulty'])->toArray();

        $this->assertSame($before, $after, 'Nothing may be created, changed or removed.');
        $this->assertSame(0, $import->refresh()->created_questions);
        $this->assertSame(0, $import->updated_questions);
        $this->assertSame(0, $import->skipped_questions);
    }

    public function test_a_decision_the_row_cannot_take_is_refused(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('Which drug?'),
            $this->row('which DRUG?'),
        ]);

        $row = $import->duplicateRows()->sole();

        $this->expectException(FellowshipImportException::class);

        $this->service()->decide($row, FellowshipImportRowDecision::UpdateExisting);
    }

    public function test_a_within_file_duplicate_kept_both_inserts_exactly_one_extra_question(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('Which drug?'),
            $this->row('which DRUG?'),
        ]);

        $this->service()->decide(
            $import->duplicateRows()->sole(),
            FellowshipImportRowDecision::KeepBoth,
        );

        $import = $this->service()->confirm($import);

        // The first row inserts, the duplicate inserts once more: two rows in,
        // two questions out, with no inconsistent update between them.
        $this->assertSame(2, $fellowship->questions()->count());
        $this->assertSame(2, $import->created_questions);
    }

    public function test_a_within_file_duplicate_skipped_inserts_only_the_first(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('Which drug?'),
            $this->row('which DRUG?'),
        ]);

        $this->service()->decide(
            $import->duplicateRows()->sole(),
            FellowshipImportRowDecision::Skip,
        );

        $this->service()->confirm($import);

        $this->assertSame(1, $fellowship->questions()->count());
    }

    public function test_resolved_decisions_survive_a_retried_confirmation(): void
    {
        [$fellowship, $import] = $this->threeDuplicates();

        $rows = $import->duplicateRows()->get();
        $this->service()->decideMany($rows, FellowshipImportRowDecision::KeepBoth);

        $this->service()->confirm($import);
        $this->service()->confirm($import->refresh());
        $this->service()->confirm($import->refresh());

        $this->assertSame(6, $fellowship->questions()->count());
        $this->assertSame(3, $import->refresh()->created_questions);
    }

    public function test_a_processed_row_cannot_be_decided_again(): void
    {
        [, $import] = $this->threeDuplicates();

        $rows = $import->duplicateRows()->get();
        $this->service()->decideMany($rows, FellowshipImportRowDecision::Skip);
        $this->service()->confirm($import);

        $row = $rows[0]->refresh();
        $this->assertTrue($row->isProcessed());

        $this->service()->decide($row, FellowshipImportRowDecision::KeepBoth);

        $this->assertSame(
            FellowshipImportRowDecision::Skip,
            $row->refresh()->decision,
            'A decision already acted on is not rewritten.',
        );
    }

    public function test_the_comparison_shows_existing_against_imported_for_a_duplicate(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, topic: 'Cardiology', text: 'Which drug?', options: 2);

        $import = $this->stage($fellowship, [
            $this->row('Which drug?', key: 'Warfarin', a: 'Aspirin', b: 'Warfarin'),
        ]);

        $row = $import->duplicateRows()->sole();
        $row->setRelation('import', $import);

        $comparison = DuplicateComparison::build($row, $existing);

        $fields = collect($comparison->fields)->keyBy('label');

        $this->assertSame('Which drug?', $fields['Question text']['existing']);
        $this->assertSame('Which drug?', $fields['Question text']['imported']);
        $this->assertFalse($fields['Question text']['conflicts']);

        $this->assertSame('Cardiology', $fields['Performance topic']['existing']);
        $this->assertSame('Cardiology', $fields['Performance topic']['imported']);

        // The answer sets differ, which is the thing worth seeing.
        $this->assertTrue($comparison->hasConflicts());
        $this->assertNotSame([], $comparison->options);

        $correct = collect($comparison->options)->firstWhere('imported_is_correct', true);
        $this->assertSame('Warfarin', $correct['imported']);
    }

    // ----------------------------------------------------------------- wizard

    /**
     * Walk the wizard to the review step over a file of three duplicates.
     */
    private function wizardOnReview(Fellowship $fellowship, array $rows)
    {
        $path = $this->uploadCsv($this->headers(), $rows, Str::uuid().'.csv');

        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload');

        $this->mapWizard($component, [
            'question_text' => 'Stem',
            'option_1' => 'A',
            'option_2' => 'B',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ]);

        return $component->call('submitMapping');
    }

    public function test_the_review_screen_starts_every_duplicate_pending(): void
    {
        $fellowship = $this->fellowship();
        foreach (['First?', 'Second?', 'Third?'] as $text) {
            $this->question($fellowship, text: $text);
        }

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [
            $this->row('First?'), $this->row('Second?'), $this->row('Third?'),
        ]);

        $import = $fellowship->imports()->sole();

        foreach ($import->duplicateRows()->get() as $row) {
            $component->assertSet("decisions.{$row->id}", '');
        }

        $this->assertSame(3, $component->instance()->unresolvedDuplicatesCount());
        $this->assertFalse($component->instance()->canConfirm());
    }

    public function test_the_review_screen_will_not_confirm_while_duplicates_are_pending(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [$this->row('First?')]);

        $component->call('confirm');

        $this->assertSame(1, $fellowship->questions()->count());
        $this->assertSame(FellowshipImportStatus::Staged, $fellowship->imports()->sole()->refresh()->status);
    }

    public function test_select_all_ticks_every_duplicate_and_a_bulk_action_resolves_them(): void
    {
        $fellowship = $this->fellowship();
        foreach (['First?', 'Second?', 'Third?'] as $text) {
            $this->question($fellowship, text: $text);
        }

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [
            $this->row('First?'), $this->row('Second?'), $this->row('Third?'),
        ]);

        $component->set('selectAll', true);

        $this->assertSame(3, $component->instance()->selectedCount());

        $component->call('applyBulkDecision', FellowshipImportRowDecision::Skip->value);

        $this->assertSame(0, $component->instance()->unresolvedDuplicatesCount());
        $this->assertTrue($component->instance()->canConfirm());

        $component->call('confirm');

        $this->assertSame(3, $fellowship->questions()->count());
        $this->assertSame(3, $fellowship->imports()->sole()->refresh()->skipped_questions);
    }

    public function test_select_all_can_be_unticked(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [$this->row('First?')]);

        $component->set('selectAll', true);
        $this->assertSame(1, $component->instance()->selectedCount());

        $component->set('selectAll', false);
        $this->assertSame(0, $component->instance()->selectedCount());
    }

    public function test_a_bulk_action_applies_to_a_partial_selection(): void
    {
        $fellowship = $this->fellowship();
        foreach (['First?', 'Second?'] as $text) {
            $this->question($fellowship, text: $text);
        }

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [$this->row('First?'), $this->row('Second?')]);

        $rows = $fellowship->imports()->sole()->duplicateRows()->get();

        $component
            ->set("selected.{$rows[0]->id}", true)
            ->call('applyBulkDecision', FellowshipImportRowDecision::KeepBoth->value);

        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $rows[0]->refresh()->decision);
        $this->assertNull($rows[1]->refresh()->decision);
        $this->assertSame(1, $component->instance()->unresolvedDuplicatesCount());
        $this->assertFalse($component->instance()->canConfirm());
    }

    public function test_a_bulk_action_offers_only_what_every_selected_row_can_take(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        $this->actingAs($this->superAdmin(), 'admin');

        // A stored duplicate plus a within-file one.
        $component = $this->wizardOnReview($fellowship, [
            $this->row('First?'),
            $this->row('Second?'),
            $this->row('second?'),
        ]);

        $rows = $fellowship->imports()->sole()->duplicateRows()->get();

        // The stored duplicate alone: all three actions.
        $stored = $rows->first(fn (FellowshipImportRow $row): bool => $row->duplicate_of_question_id !== null);
        $component->set('selected', [$stored->id => true]);

        $this->assertArrayHasKey(
            FellowshipImportRowDecision::UpdateExisting->value,
            $component->instance()->bulkDecisionOptions(),
        );

        // Both together: update disappears rather than silently skipping one.
        $component->set('selectAll', true);

        $this->assertArrayNotHasKey(
            FellowshipImportRowDecision::UpdateExisting->value,
            $component->instance()->bulkDecisionOptions(),
        );
        $this->assertArrayHasKey(
            FellowshipImportRowDecision::Skip->value,
            $component->instance()->bulkDecisionOptions(),
        );
    }

    public function test_a_decision_can_be_changed_and_cleared_from_the_review_screen(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [$this->row('First?')]);
        $row = $fellowship->imports()->sole()->duplicateRows()->sole();

        $component->set("decisions.{$row->id}", FellowshipImportRowDecision::UpdateExisting->value);
        $this->assertSame(FellowshipImportRowDecision::UpdateExisting, $row->refresh()->decision);
        $this->assertTrue($component->instance()->canConfirm());

        $component->set("decisions.{$row->id}", FellowshipImportRowDecision::KeepBoth->value);
        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $row->refresh()->decision);

        // Back to pending, which blocks confirmation again.
        $component->set("decisions.{$row->id}", '');
        $this->assertNull($row->refresh()->decision);
        $this->assertFalse($component->instance()->canConfirm());
        $this->assertSame(1, $component->instance()->unresolvedDuplicatesCount());
    }

    public function test_the_review_screen_offers_a_pending_option_and_hides_impossible_ones(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');

        $this->actingAs($this->superAdmin(), 'admin');

        $component = $this->wizardOnReview($fellowship, [
            $this->row('First?'),
            $this->row('Second?'),
            $this->row('second?'),
        ]);

        $page = $component->instance();
        $rows = $fellowship->imports()->sole()->duplicateRows()->get();

        $stored = $rows->first(fn (FellowshipImportRow $row): bool => $row->duplicate_of_question_id !== null);
        $withinFile = $rows->first(fn (FellowshipImportRow $row): bool => $row->duplicate_of_row_number !== null);

        $this->assertArrayHasKey('', $page->decisionOptions($stored), 'A pending option must be offered.');
        $this->assertArrayHasKey(FellowshipImportRowDecision::UpdateExisting->value, $page->decisionOptions($stored));
        $this->assertArrayNotHasKey(FellowshipImportRowDecision::UpdateExisting->value, $page->decisionOptions($withinFile));
    }
}
