<?php

namespace Tests\Feature\Fellowship;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\FellowshipResource\Pages\CreateFellowship;
use App\Filament\Resources\FellowshipResource\Pages\EditFellowship;
use App\Filament\Resources\FellowshipResource\Pages\ListFellowships;
use App\Filament\Resources\FellowshipResource\Pages\ViewFellowship;
use App\Filament\Resources\FellowshipResource\RelationManagers\FiltersRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\QuestionsRelationManager;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\FellowshipQuestionFormFields;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Fellowship admin pages: creating a Fellowship before its questions exist,
 * managing those questions and the filters they are classified by.
 */
class FellowshipAdminResourceTest extends FellowshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, array<string, mixed>>
     */
    private function keyedOptions(array $options): array
    {
        return collect($options)
            ->mapWithKeys(fn (array $option): array => [(string) Str::uuid() => $option])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function validOptions(): array
    {
        return [
            ['option_text' => 'Aspirin', 'is_correct' => true],
            ['option_text' => 'Warfarin', 'is_correct' => false],
        ];
    }

    public function test_a_fellowship_is_created_with_a_name_and_an_active_flag(): void
    {
        Livewire::test(CreateFellowship::class)
            ->fillForm([
                'name' => 'Emergency Medicine',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fellowships', [
            'name' => 'Emergency Medicine',
            'is_active' => true,
        ]);
    }

    public function test_a_fellowship_needs_a_name(): void
    {
        Livewire::test(CreateFellowship::class)
            ->fillForm(['name' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_a_new_fellowship_has_no_performance_classification_until_an_import(): void
    {
        Livewire::test(CreateFellowship::class)
            ->fillForm(['name' => 'Fresh Bank'])
            ->call('create')
            ->assertHasNoFormErrors();

        $fellowship = Fellowship::query()->where('name', 'Fresh Bank')->sole();

        $this->assertNull($fellowship->performance_filter_id);
        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_the_fellowship_list_shows_the_fellowships(): void
    {
        $emergency = $this->fellowship('Emergency Medicine');
        $paediatrics = Fellowship::factory()->inactive()->create(['name' => 'Paediatrics']);

        Livewire::test(ListFellowships::class)
            ->assertCanSeeTableRecords([$emergency, $paediatrics])
            ->searchTable('Emergency')
            ->assertCanSeeTableRecords([$emergency])
            ->assertCanNotSeeTableRecords([$paediatrics]);
    }

    public function test_a_fellowship_can_be_deactivated(): void
    {
        $fellowship = $this->fellowship();

        Livewire::test(EditFellowship::class, ['record' => $fellowship->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($fellowship->refresh()->is_active);
    }

    public function test_the_view_page_shows_the_questions_filters_and_import_history_tabs(): void
    {
        $fellowship = $this->fellowship();

        Livewire::test(ViewFellowship::class, ['record' => $fellowship->getKey()])
            ->assertSuccessful();

        $this->assertSame(
            ['questions', 'filters', 'imports'],
            ViewFellowship::tabKeys(),
        );
    }

    public function test_the_questions_table_lists_the_banks_questions(): void
    {
        $fellowship = $this->fellowship();
        $cardio = $this->question($fellowship, topic: 'Cardiology');
        $neuro = $this->question($fellowship, topic: 'Neurology');

        $this->questionsTable($fellowship)
            ->assertCanSeeTableRecords([$cardio, $neuro]);
    }

    public function test_a_question_may_be_added_by_hand_with_its_topic_and_options(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');

        $this->createQuestion($fellowship, [
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
            'explanation' => 'Because.',
        ])->assertHasNoActionErrors();

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame('Which antiplatelet?', $question->question_text);
        $this->assertSame($topic->getKey(), $question->performance_filter_value_id);
        $this->assertSame('Aspirin', $question->options->firstWhere('is_correct', true)?->option_text);
    }

    public function test_a_hand_written_question_needs_a_performance_topic(): void
    {
        $fellowship = $this->fellowship();

        $this->createQuestion($fellowship, [
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => null,
        ])->assertHasActionErrors(['performance_filter_value_id' => 'required']);

        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_a_hand_written_question_needs_two_options_and_one_correct_answer(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');

        $this->createQuestion($fellowship, [
            'question_text' => 'Only one option?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
        ], [['option_text' => 'Aspirin', 'is_correct' => true]])
            ->assertHasActionErrors(['options']);

        $this->createQuestion($fellowship, [
            'question_text' => 'Two correct?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
        ], [
            ['option_text' => 'Aspirin', 'is_correct' => true],
            ['option_text' => 'Warfarin', 'is_correct' => true],
        ])->assertHasActionErrors(['options']);

        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_a_dynamic_filter_value_is_saved_with_a_hand_written_question(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);
        $respiratory = $system->values()->where('value', 'Respiratory')->sole();

        $this->createQuestion($fellowship, [
            'question_text' => 'Which inhaler?',
            'difficulty' => QuizDifficulty::Medium->value,
            'performance_filter_value_id' => $topic->getKey(),
            FellowshipQuestionFormFields::FILTER_FIELD_PREFIX.$system->getKey() => $respiratory->getKey(),
        ])->assertHasNoActionErrors();

        $question = $fellowship->questions()->sole();

        $this->assertSame($respiratory->getKey(), $question->valueForFilter($system->getKey())?->fellowship_filter_value_id);
    }

    public function test_a_question_is_soft_deleted(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);

        $this->questionsTable($fellowship)
            ->callAction(TestAction::make('delete')->table($question));

        $this->assertSoftDeleted($question);
        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertSame(1, $fellowship->questions()->withTrashed()->count());
    }

    public function test_the_filters_table_lists_the_fellowships_classifications(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->filtersTable($fellowship)
            ->assertCanSeeTableRecords([$fellowship->performanceFilter, $system]);
    }

    public function test_a_filter_value_may_be_added_by_hand(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $existing = $system->values()->sole();

        $this->filtersTable($fellowship)
            ->mountAction(TestAction::make('edit')->table($system))
            ->set('mountedActions.0.data.name', 'System')
            ->set('mountedActions.0.data.values', [
                // An existing item keeps its record id as its key; a new uuid
                // key is a new value.
                (string) $existing->getKey() => ['value' => 'Cardiovascular'],
                (string) Str::uuid() => ['value' => 'Respiratory'],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            ['Cardiovascular', 'Respiratory'],
            $system->refresh()->values()->pluck('value')->all(),
        );
    }

    public function test_the_performance_topic_filter_cannot_be_deleted(): void
    {
        $fellowship = $this->fellowship();

        $this->assertFalse(FiltersRelationManager::filterCanBeDeleted($fellowship->performanceFilter));
        $this->assertSame(
            __('admin.fellowship.performance_filter_cannot_be_deleted'),
            FiltersRelationManager::deleteBlockedReason($fellowship->performanceFilter),
        );
    }

    public function test_a_filter_in_use_by_a_question_cannot_be_deleted(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->assertTrue(FiltersRelationManager::filterCanBeDeleted($system));

        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');

        $this->assertFalse(FiltersRelationManager::filterCanBeDeleted($system->refresh()));
    }

    public function test_an_unused_dynamic_filter_may_be_deleted(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->filtersTable($fellowship)
            ->callAction(TestAction::make('delete')->table($system));

        $this->assertDatabaseMissing('fellowship_filters', ['id' => $system->getKey()]);
    }

    /**
     * Mount and call the questions table's create action.
     *
     * Repeater state is keyed by item, so the options array is set wholesale
     * rather than merged into the form's default blank items.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $options
     */
    private function createQuestion(Fellowship $fellowship, array $data, ?array $options = null)
    {
        $component = $this->questionsTable($fellowship)
            ->mountAction(TestAction::make('create')->table());

        foreach ($data as $field => $value) {
            $component->set("mountedActions.0.data.{$field}", $value);
        }

        return $component
            ->set('mountedActions.0.data.options', $this->keyedOptions($options ?? $this->validOptions()))
            ->callMountedAction();
    }

    private function questionsTable(Fellowship $fellowship)
    {
        return Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $fellowship,
            'pageClass' => ViewFellowship::class,
        ]);
    }

    private function filtersTable(Fellowship $fellowship)
    {
        return Livewire::test(FiltersRelationManager::class, [
            'ownerRecord' => $fellowship,
            'pageClass' => ViewFellowship::class,
        ]);
    }
}
