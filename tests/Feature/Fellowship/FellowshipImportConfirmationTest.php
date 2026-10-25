<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportStatus;
use App\Enums\QuizDifficulty;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Services\Fellowship\FellowshipQuestionWriter;
use App\Support\Fellowship\ImportHeaderMap;

/**
 * Confirmation: the only step that writes.
 *
 * Covers what each per-row decision does, how filters and their values are
 * created and reused across imports, what an update does and does not touch,
 * and that confirming twice cannot insert anything twice.
 */
class FellowshipImportConfirmationTest extends FellowshipTestCase
{
    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'C', 'Key', 'Level', 'Area', 'Why', 'System'];
    }

    /**
     * @param  array<string, array<string, mixed>|string>  $overrides
     */
    private function map(array $overrides = []): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'C' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            'Why' => FellowshipImportMappingTarget::Explanation->value,
            'System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'System'],
            ...$overrides,
        ]);
    }

    /** @return list<string|null> */
    private function row(
        string $stem,
        string $key = 'Aspirin',
        string $level = 'Easy',
        string $area = 'Cardiology',
        ?string $system = 'Cardiovascular',
        string $a = 'Aspirin',
        string $b = 'Warfarin',
        ?string $c = 'Heparin',
        string $why = 'Because.',
    ): array {
        return [$stem, $a, $b, $c, $key, $level, $area, $why, $system];
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @param  list<string>|null  $headers
     */
    private function stage(
        Fellowship $fellowship,
        array $rows,
        ?ImportHeaderMap $map = null,
        ?array $headers = null,
        string $filename = 'questions.csv',
    ): FellowshipImport {
        $headers ??= $this->headers();

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => $filename,
            'stored_path' => $this->uploadCsv($headers, $rows, $filename),
            'headers' => $headers,
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $map ?? $this->map());
    }

    private function confirm(FellowshipImport $import): FellowshipImport
    {
        return app(FellowshipImportConfirmationService::class)->confirm($import);
    }

    public function test_confirming_creates_the_questions_with_their_options_and_correct_answer(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->confirm($this->stage($fellowship, [
            $this->row('Which antiplatelet?'),
        ]));

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertSame(1, $import->created_questions);

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame('Which antiplatelet?', $question->question_text);
        $this->assertSame('Because.', $question->explanation);
        $this->assertSame(QuizDifficulty::Easy, $question->difficulty);
        $this->assertCount(3, $question->options);
        $this->assertSame('Aspirin', $question->options->firstWhere('is_correct', true)?->option_text);
        $this->assertSame([1, 2, 3], $question->options->pluck('order')->map(fn ($o): int => (int) $o)->all());
    }

    public function test_confirming_designates_the_performance_topic_filter_and_creates_its_values(): void
    {
        // A fellowship whose performance filter has not been designated yet,
        // which is the state a freshly created one is in.
        $fellowship = Fellowship::factory()->create();

        $this->confirm($this->stage($fellowship, [
            $this->row('One?', area: 'Cardiology'),
            $this->row('Two?', area: 'Neurology'),
        ]));

        $fellowship->refresh();

        $this->assertNotNull($fellowship->performance_filter_id);
        $this->assertSame('Area', $fellowship->performanceFilter->name, 'The mapped column names the filter.');
        $this->assertTrue($fellowship->performanceFilter->is_performance);
        $this->assertSame(
            ['Cardiology', 'Neurology'],
            $fellowship->performanceFilter->values()->pluck('value')->all(),
        );
    }

    public function test_an_import_lands_on_a_performance_filter_an_admin_designated_by_hand(): void
    {
        // The designation an admin makes on the Filters tab is the same fact
        // the first import would have written, so an import into a bank already
        // set up by hand must reuse it rather than fork the classification —
        // whatever the file happens to call the column.
        $fellowship = Fellowship::factory()->create();

        /** @var FellowshipFilter $manual */
        $manual = $fellowship->filters()->create(['name' => 'Performance Topic', 'is_performance' => true]);
        $fellowship->update(['performance_filter_id' => $manual->getKey()]);
        $manual->values()->create(['value' => 'Cardiology']);

        $this->confirm($this->stage($fellowship, [
            $this->row('One?', area: 'Cardiology'),
            $this->row('Two?', area: 'Neurology'),
        ]));

        $fellowship->refresh();

        $this->assertSame($manual->getKey(), $fellowship->performance_filter_id);
        $this->assertSame('Performance Topic', $fellowship->performanceFilter->name, 'The column must not rename it.');
        $this->assertSame(1, $fellowship->filters()->where('is_performance', true)->count());
        $this->assertSame(
            ['Cardiology', 'Neurology'],
            $fellowship->performanceFilter->values()->pluck('value')->all(),
        );
        $this->assertSame(
            1,
            $fellowship->performanceFilter->values()->where('value', 'Cardiology')->count(),
            'The hand-made value must be reused, not duplicated.',
        );
        $this->assertSame(2, $fellowship->questions()->whereNotNull('performance_filter_value_id')->count());
    }

    public function test_an_import_reuses_a_dynamic_filter_an_admin_created_by_hand(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        // The file's column maps onto the filter that already exists.
        $this->confirm($this->stage(
            $fellowship,
            [$this->row('One?', system: 'Cardiovascular'), $this->row('Two?', system: 'Respiratory')],
            $this->map([
                'System' => [
                    'target' => FellowshipImportMappingTarget::ExistingFilter->value,
                    'filter_id' => $system->getKey(),
                ],
            ]),
        ));

        $this->assertSame(1, $fellowship->filters()->where('name', 'System')->count(), 'The filter must not fork.');
        $this->assertEqualsCanonicalizing(
            ['Cardiovascular', 'Respiratory'],
            $system->refresh()->values()->pluck('value')->all(),
        );
        $this->assertSame(2, $system->questionFilterValues()->count());
    }

    public function test_a_later_import_maps_a_differently_named_column_onto_the_same_performance_filter(): void
    {
        $fellowship = Fellowship::factory()->create();

        $this->confirm($this->stage($fellowship, [$this->row('One?', area: 'Cardiology')]));

        $filterId = $fellowship->refresh()->performance_filter_id;

        // Second file calls the column something else entirely.
        $headers = ['Stem', 'A', 'B', 'C', 'Key', 'Level', 'Specialty', 'Why', 'System'];

        $this->confirm($this->stage(
            $fellowship,
            [$this->row('Two?', area: 'Cardiology')],
            $this->map(['Area' => FellowshipImportMappingTarget::Ignore->value, 'Specialty' => FellowshipImportMappingTarget::PerformanceTopic->value]),
            $headers,
            'second.csv',
        ));

        $fellowship->refresh();

        $this->assertSame($filterId, $fellowship->performance_filter_id, 'The classification must not fork.');
        $this->assertSame(1, $fellowship->filters()->where('is_performance', true)->count());
        $this->assertSame(
            1,
            $fellowship->performanceFilter->values()->where('value', 'Cardiology')->count(),
            'The same topic must not be stored twice.',
        );
    }

    public function test_the_performance_topic_is_not_duplicated_into_the_dynamic_filter_pivot(): void
    {
        $fellowship = Fellowship::factory()->create();

        $this->confirm($this->stage($fellowship, [$this->row('One?')]));

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->sole();

        $this->assertNotNull($question->performance_filter_value_id);

        // Only the dynamic filter — the topic lives on the question's own
        // column and nowhere else.
        $this->assertSame(1, $question->filterValues()->count());
        $this->assertSame(
            'System',
            $question->filterValues()->with('filter')->first()->filter->name,
        );
    }

    public function test_a_new_dynamic_filter_and_its_values_are_created_on_confirmation(): void
    {
        $fellowship = $this->fellowship();

        $this->confirm($this->stage($fellowship, [
            $this->row('One?', system: 'Cardiovascular'),
            $this->row('Two?', system: 'Respiratory'),
        ]));

        $filter = $fellowship->dynamicFilters()->sole();

        $this->assertSame('System', $filter->name);
        $this->assertSame(['Cardiovascular', 'Respiratory'], $filter->values()->pluck('value')->all());
    }

    public function test_an_existing_filter_is_reused_rather_than_forked(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->confirm($this->stage(
            $fellowship,
            [
                $this->row('One?', system: 'Cardiovascular'),
                $this->row('Two?', system: 'Respiratory'),
            ],
            $this->map([
                'System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existing->getKey()],
            ]),
        ));

        $this->assertSame(1, $fellowship->dynamicFilters()->count());
        $this->assertSame(
            ['Cardiovascular', 'Respiratory'],
            $existing->refresh()->values()->pluck('value')->all(),
            'The existing value is reused and only the new one is added.',
        );
    }

    public function test_a_new_filter_whose_name_already_exists_lands_on_the_existing_one(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        // The admin chose "new filter" but typed a name the fellowship has,
        // differing only in case: reuse rather than a second "system".
        $this->confirm($this->stage(
            $fellowship,
            [$this->row('One?', system: 'Respiratory')],
            $this->map(['System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'system']]),
        ));

        $this->assertSame(1, $fellowship->dynamicFilters()->count());
        $this->assertSame($existing->getKey(), $fellowship->dynamicFilters()->sole()->getKey());
    }

    public function test_a_filter_absent_from_a_later_import_is_left_untouched(): void
    {
        $fellowship = $this->fellowship();

        $this->confirm($this->stage($fellowship, [$this->row('One?', system: 'Cardiovascular')]));

        $systemFilter = $fellowship->dynamicFilters()->sole();

        // A second file with no System column at all.
        $headers = ['Stem', 'A', 'B', 'C', 'Key', 'Level', 'Area', 'Why'];

        $this->confirm($this->stage(
            $fellowship,
            [[...array_slice($this->row('Two?'), 0, 8)]],
            ImportHeaderMap::fromArray([
                'Stem' => FellowshipImportMappingTarget::QuestionText->value,
                'A' => FellowshipImportMappingTarget::AnswerOption->value,
                'B' => FellowshipImportMappingTarget::AnswerOption->value,
                'C' => FellowshipImportMappingTarget::AnswerOption->value,
                'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
                'Level' => FellowshipImportMappingTarget::Difficulty->value,
                'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
                'Why' => FellowshipImportMappingTarget::Explanation->value,
            ]),
            $headers,
            'second.csv',
        ));

        $this->assertSame(1, $fellowship->dynamicFilters()->count());
        $this->assertSame(['Cardiovascular'], $systemFilter->refresh()->values()->pluck('value')->all());

        // And the question imported without that column simply has no value
        // for it, which is allowed.
        $second = $fellowship->questions()->where('question_text', 'Two?')->sole();
        $this->assertSame(0, $second->filterValues()->count());
    }

    public function test_a_duplicate_starts_with_no_decision_at_all(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $row = $import->duplicateRows()->sole();

        $this->assertNull($row->decision, 'Nothing is decided on the admin\'s behalf.');
        $this->assertTrue($row->awaitsDecision());
        $this->assertSame(1, $import->unresolvedDuplicatesCount());
        $this->assertTrue($import->hasUnresolvedDuplicates());
    }

    public function test_an_undecided_duplicate_blocks_the_whole_import(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?');

        // One good row alongside the undecided duplicate: the import is
        // blocked as a whole rather than importing part of the file.
        $import = $this->stage($fellowship, [
            $this->row('Which drug?'),
            $this->row('A brand new question?'),
        ]);

        try {
            $this->confirm($import);
            $this->fail('An undecided duplicate must block confirmation.');
        } catch (FellowshipImportException $exception) {
            $this->assertStringContainsString('awaiting a decision', $exception->getMessage());
        }

        $this->assertSame(1, $fellowship->questions()->count(), 'Nothing new may be written.');
        $this->assertSame(FellowshipImportStatus::Staged, $import->refresh()->status);
        $this->assertNull($import->rows()->first()->outcome);
    }

    public function test_the_unresolved_count_falls_as_decisions_are_made(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');
        $this->question($fellowship, text: 'Second?');

        $import = $this->stage($fellowship, [$this->row('First?'), $this->row('Second?')]);

        $this->assertSame(2, $import->unresolvedDuplicatesCount());

        $rows = $import->duplicateRows()->get();
        app(FellowshipImportConfirmationService::class)->decide($rows[0], FellowshipImportRowDecision::Skip);

        $this->assertSame(1, $import->refresh()->unresolvedDuplicatesCount());

        app(FellowshipImportConfirmationService::class)->decide($rows[1], FellowshipImportRowDecision::Skip);

        $this->assertSame(0, $import->refresh()->unresolvedDuplicatesCount());
        $this->assertFalse($import->hasUnresolvedDuplicates());
    }

    public function test_an_explicitly_skipped_duplicate_is_skipped(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $this->decide($import, FellowshipImportRowDecision::Skip);

        $import = $this->confirm($import);

        $this->assertSame(1, $import->skipped_questions);
        $this->assertSame(0, $import->created_questions);
        $this->assertSame(1, $fellowship->questions()->count());
        $this->assertSame(FellowshipImportRowOutcome::Skipped, $import->rows()->first()->outcome);
    }

    public function test_a_decision_may_be_changed_before_confirmation(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $row = $import->duplicateRows()->sole();
        $service = app(FellowshipImportConfirmationService::class);

        $service->decide($row, FellowshipImportRowDecision::Skip);
        $this->assertSame(FellowshipImportRowDecision::Skip, $row->refresh()->decision);

        $service->decide($row, FellowshipImportRowDecision::KeepBoth);
        $this->assertSame(FellowshipImportRowDecision::KeepBoth, $row->refresh()->decision);

        // And back to pending, which blocks confirmation again.
        $service->undecide($row);
        $this->assertNull($row->refresh()->decision);
        $this->assertSame(1, $import->refresh()->unresolvedDuplicatesCount());
    }

    public function test_keep_both_inserts_a_second_question(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $this->decide($import, FellowshipImportRowDecision::KeepBoth);

        $import = $this->confirm($import);

        $this->assertSame(1, $import->created_questions);
        $this->assertSame(2, $fellowship->questions()->count());
    }

    public function test_update_existing_rewrites_the_stored_question(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, topic: 'Cardiology', difficulty: QuizDifficulty::Easy, text: 'Which drug?');

        $import = $this->stage($fellowship, [
            $this->row('Which drug?', key: 'Warfarin', level: 'Hard', area: 'Neurology', why: 'Updated reason.'),
        ]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);

        $import = $this->confirm($import);

        $this->assertSame(1, $import->updated_questions);
        $this->assertSame(1, $fellowship->questions()->count(), 'An update must not also insert.');

        $existing->refresh()->load(['options', 'performanceTopic']);

        $this->assertSame(QuizDifficulty::Hard, $existing->difficulty);
        $this->assertSame('Updated reason.', $existing->explanation);
        $this->assertSame('Neurology', $existing->performanceTopic->value);
        $this->assertSame('Warfarin', $existing->options->firstWhere('is_correct', true)?->option_text);
        $this->assertSame(['Aspirin', 'Warfarin', 'Heparin'], $existing->options->pluck('option_text')->all());
    }

    public function test_update_sets_a_mapped_optional_filter_value(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($existing, $system, 'Cardiovascular');

        $import = $this->stage(
            $fellowship,
            [$this->row('Which drug?', system: 'Respiratory')],
            $this->map(['System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $system->getKey()]]),
        );
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertSame(
            'Respiratory',
            $existing->refresh()->valueForFilter($system->getKey())?->value->value,
        );
    }

    public function test_update_preserves_a_filter_value_whose_column_is_absent_from_the_import(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($existing, $system, 'Cardiovascular');

        // The import does not map the System column at all.
        $import = $this->stage(
            $fellowship,
            [$this->row('Which drug?', system: 'Respiratory')],
            $this->map(['System' => FellowshipImportMappingTarget::Ignore->value]),
        );
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertSame(
            'Cardiovascular',
            $existing->refresh()->valueForFilter($system->getKey())?->value->value,
            'A filter the file never mentioned must be left alone.',
        );
    }

    public function test_update_clears_a_filter_value_whose_mapped_cell_is_empty(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($existing, $system, 'Cardiovascular');

        $import = $this->stage(
            $fellowship,
            [$this->row('Which drug?', system: '')],
            $this->map(['System' => ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $system->getKey()]]),
        );
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertNull(
            $existing->refresh()->valueForFilter($system->getKey()),
            'A mapped, empty cell is an explicit instruction to clear.',
        );
    }

    public function test_a_within_file_duplicate_cannot_be_decided_as_an_update(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('Which drug?'),
            $this->row('which drug?'),
        ]);

        $row = $import->duplicateRows()->sole();

        $this->expectException(FellowshipImportException::class);

        app(FellowshipImportConfirmationService::class)->decide($row, FellowshipImportRowDecision::UpdateExisting);
    }

    public function test_decisions_are_made_per_row(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'First?');
        $this->question($fellowship, text: 'Second?');

        $import = $this->stage($fellowship, [
            $this->row('First?'),
            $this->row('Second?'),
        ]);

        $rows = $import->duplicateRows()->get();

        app(FellowshipImportConfirmationService::class)->decide($rows[0], FellowshipImportRowDecision::KeepBoth);
        app(FellowshipImportConfirmationService::class)->decide($rows[1], FellowshipImportRowDecision::Skip);

        $import = $this->confirm($import);

        $this->assertSame(1, $import->created_questions);
        $this->assertSame(1, $import->skipped_questions);
        $this->assertSame(3, $fellowship->questions()->count());
    }

    public function test_confirming_twice_does_not_insert_twice(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('One?'),
            $this->row('Two?'),
        ]);

        $this->confirm($import);
        $this->confirm($import->refresh());
        $this->confirm($import->refresh());

        $this->assertSame(2, $fellowship->questions()->count());
        $this->assertSame(2, $import->refresh()->created_questions);
    }

    public function test_a_confirmation_interrupted_part_way_resumes_rather_than_repeats(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('One?'),
            $this->row('Two?'),
            $this->row('Three?'),
        ]);

        // Simulate the first row having been applied before the process died:
        // the row is stamped, the import is still staged.
        $first = $import->rows()->orderBy('row_number')->first();
        $created = app(FellowshipQuestionWriter::class)
            ->create($fellowship, $first->payload(), $this->map());
        $first->update([
            'outcome' => FellowshipImportRowOutcome::Created,
            'fellowship_question_id' => $created->getKey(),
            'processed_at' => now(),
        ]);

        $import = $this->confirm($import);

        $this->assertSame(3, $fellowship->questions()->count(), 'The stamped row must not be applied again.');
        $this->assertSame(3, $import->created_questions);
    }

    public function test_an_invalid_row_is_never_applied(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->confirm($this->stage($fellowship, [
            $this->row('Good?'),
            $this->row('Bad?', level: 'Nightmare'),
        ]));

        $this->assertSame(1, $fellowship->questions()->count());
        $this->assertSame(1, $import->created_questions);
        $this->assertSame(['Good?'], $fellowship->questions()->pluck('question_text')->all());
    }

    public function test_an_update_whose_target_has_been_deleted_fails_rather_than_inserting(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);

        $existing->forceDelete();

        $import = $this->confirm($import);

        // The admin asked for an update, so skipping would quietly do something
        // else. Reported as failed, with nothing inserted in its place.
        $row = $import->rows()->sole();

        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertSame(0, $import->created_questions);
        $this->assertSame(0, $import->skipped_questions);
        $this->assertSame(FellowshipImportRowOutcome::Failed, $row->outcome);
        $this->assertStringContainsString('no longer exists', implode(' ', $row->errors ?? []));
    }

    public function test_update_existing_is_not_offered_once_its_target_is_gone(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage($fellowship, [$this->row('Which drug?')]);
        $row = $import->duplicateRows()->sole();

        $this->assertTrue($row->canUpdateExisting());

        $existing->forceDelete();

        $this->assertFalse($row->refresh()->canUpdateExisting());
        $this->assertNotContains(
            FellowshipImportRowDecision::UpdateExisting,
            $row->availableDecisions(),
        );
    }

    private function decide(FellowshipImport $import, FellowshipImportRowDecision $decision): void
    {
        $import->duplicateRows()->get()->each(
            fn (FellowshipImportRow $row) => app(FellowshipImportConfirmationService::class)->decide($row, $decision),
        );
    }
}
