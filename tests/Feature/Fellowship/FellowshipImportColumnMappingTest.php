<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Support\Fellowship\ImportHeaderMap;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Column Mapping step: the system's fields on the left, the file's own
 * columns on the right.
 *
 * Three things are being pinned down here. That the columns read out of the
 * workbook actually reach every dropdown — the step is useless if they do not.
 * That one column can fill one field and no more, in the dropdowns and again on
 * submit. And that the columns left over are the Fellowship's dynamic filters to
 * make, without anything being created before the import is confirmed.
 */
class FellowshipImportColumnMappingTest extends FellowshipTestCase
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
        ];
    }

    /**
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

    /**
     * Upload a real workbook and stop on the mapping step, the way the admin
     * arrives at it.
     */
    private function wizardOnMapping(Fellowship $fellowship, ?array $headers = null, ?array $rows = null)
    {
        $path = $this->storedXlsx(
            $headers ?? $this->headers(),
            $rows ?? $this->rows(),
            Str::uuid().'.xlsx',
        );

        return Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    /**
     * The regression behind "the column names are not visible": the mapping
     * schema is generated from the uploaded file's headers, and it has to be
     * generated again once they are known.
     */
    public function test_the_files_columns_reach_the_mapping_step(): void
    {
        $component = Livewire::test(ImportFellowshipQuestions::class, ['record' => $this->fellowship()->getKey()])
            ->set('uploadData.file', [$this->xlsxUpload($this->headers(), $this->rows())])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $this->assertSame($this->headers(), $component->instance()->headers());

        // Every dropdown is offered every column of the file, by its own name.
        $this->assertSame(
            array_combine($this->headers(), $this->headers()),
            $component->instance()->headerOptions(),
        );

        // And the step renders them rather than an empty form.
        $html = $component->html();

        foreach ($this->headers() as $header) {
            $this->assertStringContainsString($header, $html, "The column [{$header}] is not on screen.");
        }
    }

    public function test_the_mapping_step_starts_with_a_slot_for_every_field_it_requires(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $state = $component->get('mappingData');

        foreach (FellowshipImportMappingTarget::systemFields() as $target) {
            $this->assertArrayHasKey($target->value, $state['system']);
        }

        // Two option slots at the least, and no more spare slots than the file
        // has columns left to fill them.
        $this->assertGreaterThanOrEqual(
            ImportHeaderMap::MINIMUM_OPTION_COLUMNS,
            $component->instance()->answerOptionSlotCount(),
        );
        $this->assertArrayHasKey('option_1', $state['system']);
        $this->assertArrayHasKey('option_2', $state['system']);
    }

    public function test_a_mapped_column_is_reported_as_taken_and_is_no_longer_spare(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $this->mapWizard($component, ['question_text' => 'Stem']);

        $page = $component->instance();

        $this->assertSame(['Stem'], $page->headersClaimedBySystemFields());
        $this->assertNotContains('Stem', $page->unclaimedHeaders());

        // The field that claimed it still offers it to itself, so the admin can
        // see and change their own choice.
        $this->assertNotContains('Stem', $page->headersClaimedBySystemFields(except: 'question_text'));
    }

    /**
     * The visible half of "a column is used once": the column one field has
     * taken is greyed out in every other dropdown, rather than left there to be
     * picked and then rejected.
     */
    public function test_a_claimed_column_is_disabled_in_every_other_dropdown(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $this->mapWizard($component, ['question_text' => 'Stem']);

        $page = $component->instance();

        foreach (['difficulty', 'correct_answer', 'performance_topic', 'option_1', 'option_2'] as $field) {
            /** @var Select $select */
            $select = $page->getSchemaComponent('mappingForm.system.'.$field);

            $this->assertNotNull($select, "The [{$field}] dropdown is missing.");
            $this->assertTrue(
                $select->isOptionDisabled('Stem', 'Stem'),
                "The [{$field}] dropdown still offers a column the question text has taken.",
            );
            $this->assertNotContains('Stem', array_keys($select->getEnabledOptions()));
        }

        // The field that took it still offers it, so the admin can see and
        // change their own choice.
        /** @var Select $owner */
        $owner = $page->getSchemaComponent('mappingForm.system.question_text');

        $this->assertFalse($owner->isOptionDisabled('Stem', 'Stem'));
        $this->assertContains('Stem', array_keys($owner->getEnabledOptions()));
    }

    /**
     * The whole point of C: the map is keyed by column, so a second claim on one
     * column would replace the first. It is refused instead.
     */
    public function test_one_column_cannot_fill_two_system_fields(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping());

        // Point Difficulty at the column the question text already uses.
        $component->set('mappingData.system.difficulty', 'Stem')
            ->call('submitMapping')
            ->assertHasErrors('mappingData.system.difficulty')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $this->assertSame(FellowshipImportStatus::Draft, $fellowship->imports()->sole()->status);
        $this->assertSame(0, $fellowship->questions()->count(), 'Nothing is staged from a mapping that was refused.');
    }

    public function test_a_column_an_answer_option_slot_has_taken_cannot_also_be_a_system_field(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping());

        // Both option slots on the same column.
        $component->set('mappingData.system.option_2', 'A')
            ->call('submitMapping')
            ->assertHasErrors('mappingData.system.option_2')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $this->assertSame(FellowshipImportStatus::Draft, $fellowship->imports()->sole()->status);
    }

    /**
     * The step may not be left while a required field has no column, and it
     * says which fields those are rather than only refusing.
     */
    public function test_the_step_names_the_required_fields_that_have_no_column_yet(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());
        $page = $component->instance();

        $this->assertFalse($page->isMappingComplete());
        $this->assertNotSame([], $page->unmappedRequiredFields());

        $this->mapWizard($component, $this->systemFieldMapping());

        $page = $component->instance();

        $this->assertSame([], $page->unmappedRequiredFields());
        $this->assertTrue($page->isMappingComplete());
    }

    public function test_one_mapped_answer_option_is_not_enough_to_continue(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $mapping = $this->systemFieldMapping();
        unset($mapping['option_2']);

        $this->mapWizard($component, $mapping);

        $this->assertFalse($component->instance()->isMappingComplete());

        $component->call('submitMapping')
            ->assertHasErrors()
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);
    }

    /**
     * D: a left-over column becomes a filter, and only at confirmation time.
     */
    public function test_a_left_over_column_becomes_a_new_filter_only_once_the_import_is_confirmed(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping(), [
            6 => [
                'target' => FellowshipImportMappingTarget::NewFilter->value,
                'filter_id' => null,
                'filter_name' => 'Body System',
            ],
        ]);

        $component->call('submitMapping')->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $this->assertSame(
            0,
            $fellowship->dynamicFilters()->count(),
            'Mapping a new filter must not create it.',
        );

        $component->call('confirm');

        $this->assertSame('Body System', $fellowship->dynamicFilters()->sole()->name);
    }

    public function test_a_left_over_column_can_feed_a_filter_the_fellowship_already_has(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping(), [
            6 => [
                'target' => FellowshipImportMappingTarget::ExistingFilter->value,
                'filter_id' => $existing->getKey(),
                'filter_name' => null,
            ],
        ]);

        $component->call('submitMapping')->call('confirm');

        $this->assertSame(
            1,
            $fellowship->dynamicFilters()->count(),
            'An existing filter is reused, not forked.',
        );
        $this->assertSame($existing->getKey(), $fellowship->dynamicFilters()->sole()->getKey());
    }

    /**
     * The Performance Topic is a system field with one column of its own, and a
     * Fellowship has exactly one of it, so its filter is not on offer as a
     * dynamic filter: a second column feeding it would fork the classification.
     */
    public function test_the_performance_filter_is_not_offered_as_a_dynamic_filter(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $component = $this->wizardOnMapping($fellowship);

        // The left-over section only opens once the required fields have their
        // columns, so map them before looking at what it offers.
        $this->mapWizard($component, $this->systemFieldMapping());

        /** @var Select $select */
        $select = $component->instance()->getSchemaComponent('mappingForm.column_6.filter_id');

        $this->assertNotNull($select, 'The left-over column has no existing-filter dropdown.');
        $this->assertSame(
            [$existing->getKey() => 'System'],
            $select->getOptions(),
            'Only the dynamic filters of this fellowship may be chosen here.',
        );
        $this->assertArrayNotHasKey($fellowship->performance_filter_id, $select->getOptions());
    }

    /**
     * E: a sample from the file beside each column, so a mapping can be checked
     * rather than taken on trust.
     */
    public function test_each_column_is_shown_a_value_from_the_file(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $samples = $component->instance()->sampleValues();

        $this->assertSame('Which antiplatelet?', $samples['Stem']);
        $this->assertSame('Cardiology', $samples['Area']);
        $this->assertStringContainsString('Cardiology', (string) $component->instance()->sampleFor('Area'));
    }

    /**
     * F: the stored map is still header-keyed, so staging, confirmation and
     * every import already on record read exactly as before.
     */
    public function test_the_stored_map_is_still_keyed_by_the_files_own_columns(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping());
        $component->call('submitMapping');

        $map = $fellowship->imports()->sole()->header_map;

        $this->assertSame(FellowshipImportMappingTarget::QuestionText->value, $map['Stem']['target']);
        $this->assertSame(FellowshipImportMappingTarget::AnswerOption->value, $map['A']['target']);
        $this->assertSame(FellowshipImportMappingTarget::PerformanceTopic->value, $map['Area']['target']);
    }

    /**
     * Answer options are imported in slot order, not file order: slot 1 is the
     * question's first option whichever column the admin put there.
     */
    public function test_answer_options_are_imported_in_the_order_their_slots_were_filled(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $mapping = $this->systemFieldMapping();
        $mapping['option_1'] = 'B';
        $mapping['option_2'] = 'A';

        $this->mapWizard($component, $mapping);
        $component->call('submitMapping')->call('confirm');

        $options = $fellowship->questions()->sole()->options()->orderBy('id')->pluck('option_text')->all();

        $this->assertSame(['Warfarin', 'Aspirin'], $options);
    }

    /**
     * Going back to mapping shows what the admin chose, read back out of the
     * header-keyed map into the field-keyed form.
     */
    public function test_going_back_to_mapping_restores_the_stored_mapping(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->systemFieldMapping(), [
            6 => [
                'target' => FellowshipImportMappingTarget::NewFilter->value,
                'filter_id' => null,
                'filter_name' => 'Body System',
            ],
        ]);

        $component->call('submitMapping')
            ->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW)
            ->call('backToMapping')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING)
            ->assertSet('mappingData.system.question_text', 'Stem')
            ->assertSet('mappingData.system.option_1', 'A')
            ->assertSet('mappingData.system.option_2', 'B')
            ->assertSet('mappingData.system.performance_topic', 'Area')
            ->assertSet('mappingData.column_6.target', FellowshipImportMappingTarget::NewFilter->value)
            ->assertSet('mappingData.column_6.filter_name', 'Body System');

        $this->assertTrue($component->instance()->isMappingComplete());
    }

    /**
     * A column with no name still has to be mappable to something, or every
     * column after it would shift by one. It keeps a placeholder name and the
     * admin can simply leave it out.
     */
    public function test_a_column_with_no_name_keeps_its_place_under_a_placeholder(): void
    {
        $component = $this->wizardOnMapping(
            $this->fellowship(),
            headers: ['Stem', '', 'B', '', 'Level', 'Area'],
            rows: [['q', 'Aspirin', 'Warfarin', 'x', 'Easy', 'Cardiology']],
        );

        $this->assertSame(
            ['Stem', 'Column 2', 'B', 'Column 4', 'Level', 'Area'],
            $component->instance()->headers(),
            'A blank column name must not shift the columns after it.',
        );
    }

    /**
     * A file too narrow to hold a question is almost always a file whose first
     * row is not its column names. Saying so beats a mapping step the admin
     * cannot complete.
     */
    public function test_a_file_with_fewer_columns_than_a_question_needs_is_refused(): void
    {
        $fellowship = $this->fellowship();
        $path = $this->storedXlsx(['Stem', 'A', 'B'], [['q', 'a', 'b']], Str::uuid().'.xlsx');

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);

        $this->assertSame(0, $fellowship->imports()->count());
    }

    /**
     * A duplicated column name is disambiguated rather than collapsed, so two
     * identically-named option columns stay two separate columns to map.
     */
    public function test_two_columns_with_the_same_name_stay_two_mappable_columns(): void
    {
        $component = $this->wizardOnMapping(
            $this->fellowship(),
            headers: ['Stem', 'Option', 'Option', 'Key', 'Level', 'Area'],
            rows: [['q', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology']],
        );

        $headers = $component->instance()->headers();

        $this->assertSame(['Stem', 'Option', 'Option (2)', 'Key', 'Level', 'Area'], $headers);
        $this->assertCount(6, $component->instance()->headerOptions());
    }

    /**
     * Problem 4: an Arabic panel must not be handed a list of English field
     * keys inside an Arabic sentence.
     */
    public function test_mapping_messages_are_localized_rather_than_mixed(): void
    {
        App::setLocale('ar');

        $message = FellowshipImportException::incompleteMapping([
            FellowshipImportMappingTarget::QuestionText->value,
            FellowshipImportMappingTarget::Difficulty->value,
        ])->getMessage();

        $this->assertStringNotContainsString('question_text', $message);
        $this->assertStringNotContainsString('difficulty', $message);
        $this->assertStringContainsString(__('admin.fellowship.target_question_text'), $message);
        $this->assertStringContainsString(__('admin.fellowship.target_difficulty'), $message);

        $duplicate = FellowshipImportException::duplicateColumnAssignment('Stem')->getMessage();

        $this->assertStringContainsString('Stem', $duplicate);
        $this->assertNotSame('admin.fellowship.import_error_duplicate_column', $duplicate);
    }

    /**
     * Every key the mapping step renders exists in both locales, so neither
     * panel falls back to showing a translation key.
     */
    public function test_the_mapping_steps_wording_exists_in_both_locales(): void
    {
        $keys = [
            'import_step_mapping',
            'import_step_mapping_hint',
            'import_section_system_fields',
            'import_section_system_fields_hint',
            'import_section_extra_columns',
            'import_section_extra_columns_hint',
            'import_headers_detected',
            'import_headers_detected_count',
            'import_column_unmapped',
            'import_column_sample',
            'import_column_claimed_twice',
            'import_column_not_available',
            'field_import_answer_option_number',
            'import_error_too_few_columns',
            'import_error_incomplete_mapping',
            'import_error_duplicate_column',
        ];

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame(
                    'admin.fellowship.'.$key,
                    __('admin.fellowship.'.$key),
                    "[{$key}] is missing from the [{$locale}] translations.",
                );
            }
        }
    }
}
