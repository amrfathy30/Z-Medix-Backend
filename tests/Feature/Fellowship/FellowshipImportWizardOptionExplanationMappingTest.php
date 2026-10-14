<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The mapping step's two new slots: the optional Title field, and one optional
 * explanation column per numbered answer option.
 *
 * The alignment is the whole point. An explanation is stored against the option
 * it explains, so an admin who maps option slots 1, 2 and 4 and explains only
 * the fourth must end up with that explanation on the question's third option —
 * the one that slot actually became — and not on whichever answer happens to
 * sit third in the file.
 */
class FellowshipImportWizardOptionExplanationMappingTest extends FellowshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'Heading', 'A', 'Why A', 'B', 'Why B', 'C', 'Why C', 'D', 'Why D', 'Key', 'Level', 'Area'];
    }

    /** @return list<list<string|null>> */
    private function rows(): array
    {
        return [[
            'Which antiplatelet?',
            'Antiplatelet choice',
            'Aspirin', 'Correct: first line.',
            'Warfarin', 'Incorrect: an anticoagulant.',
            'Heparin', 'Incorrect: parenteral.',
            'Clopidogrel', 'Incorrect: not first line.',
            'Aspirin', 'Easy', 'Cardiology',
        ]];
    }

    private function wizardOnMapping(Fellowship $fellowship)
    {
        $path = $this->storedXlsx($this->headers(), $this->rows(), Str::uuid().'.xlsx');

        return Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => $path])
            ->call('submitUpload')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);
    }

    /** @return array<string, string> */
    private function fullMapping(): array
    {
        return [
            'question_text' => 'Stem',
            'title' => 'Heading',
            'option_1' => 'A',
            'option_explanation_1' => 'Why A',
            'option_2' => 'B',
            'option_explanation_2' => 'Why B',
            'option_3' => 'C',
            'option_explanation_3' => 'Why C',
            'option_4' => 'D',
            'option_explanation_4' => 'Why D',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ];
    }

    private function storedMap(Fellowship $fellowship): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray(
            $fellowship->imports()->latest('id')->firstOrFail()->header_map ?? [],
        );
    }

    public function test_the_mapping_step_offers_a_title_slot_and_an_explanation_slot_per_option(): void
    {
        $this->wizardOnMapping($this->fellowship())
            ->assertSet('mappingData.system.title', null)
            ->assertSet('mappingData.system.option_explanation_1', null)
            ->assertSet('mappingData.system.option_explanation_2', null)
            ->assertSet('mappingData.system.option_explanation_3', null);
    }

    public function test_neither_new_slot_is_required_to_continue(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $this->mapWizard($component, [
            'question_text' => 'Stem',
            'option_1' => 'A',
            'option_2' => 'B',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ]);

        $this->assertTrue($component->instance()->isMappingComplete());
        $this->assertSame([], $component->instance()->unmappedRequiredFields());
    }

    public function test_the_wizard_stores_the_title_and_the_option_explanation_columns(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->fullMapping());

        $component->call('submitMapping')->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $map = $this->storedMap($fellowship);

        $this->assertSame('Heading', $map->headerFor(FellowshipImportMappingTarget::Title));
        $this->assertSame(
            [1 => 'Why A', 2 => 'Why B', 3 => 'Why C', 4 => 'Why D'],
            $map->optionExplanationHeaders(),
        );
    }

    public function test_an_explanation_is_stored_against_the_option_its_slot_becomes(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        // Option slot 3 is left unmapped, so the column in slot 4 becomes the
        // question's third option — and its explanation has to follow it.
        $this->mapWizard($component, [
            'question_text' => 'Stem',
            'option_1' => 'A',
            'option_2' => 'B',
            'option_4' => 'D',
            'option_explanation_4' => 'Why D',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ]);

        $component->call('submitMapping')->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $this->assertSame([3 => 'Why D'], $this->storedMap($fellowship)->optionExplanationHeaders());

        $component->call('confirm');

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame(['Aspirin', 'Warfarin', 'Clopidogrel'], $question->options->pluck('option_text')->all());
        $this->assertSame([null, null, 'Incorrect: not first line.'], $question->options->pluck('explanation')->all());
    }

    public function test_an_explanation_for_an_unmapped_option_is_left_out(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        // An explanation for option slot 3, with no option in that slot.
        $this->mapWizard($component, [
            'question_text' => 'Stem',
            'option_1' => 'A',
            'option_2' => 'B',
            'option_explanation_3' => 'Why C',
            'correct_answer' => 'Key',
            'difficulty' => 'Level',
            'performance_topic' => 'Area',
        ]);

        $component->call('submitMapping')->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $this->assertSame([], $this->storedMap($fellowship)->optionExplanationHeaders());
    }

    public function test_a_column_cannot_fill_an_option_and_its_explanation_at_once(): void
    {
        $component = $this->wizardOnMapping($this->fellowship());

        $mapping = $this->fullMapping();
        $mapping['option_explanation_1'] = 'A';

        $this->mapWizard($component, $mapping);

        $component->call('submitMapping')
            ->assertHasErrors('mappingData.system.option_explanation_1')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);
    }

    public function test_going_back_to_mapping_shows_the_columns_the_admin_chose(): void
    {
        $fellowship = $this->fellowship();
        $component = $this->wizardOnMapping($fellowship);

        $this->mapWizard($component, $this->fullMapping());
        $component->call('submitMapping')->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);

        $component->call('backToMapping')
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING)
            ->assertSet('mappingData.system.title', 'Heading')
            ->assertSet('mappingData.system.option_explanation_1', 'Why A')
            ->assertSet('mappingData.system.option_explanation_2', 'Why B')
            ->assertSet('mappingData.system.option_explanation_3', 'Why C')
            ->assertSet('mappingData.system.option_explanation_4', 'Why D');
    }
}
