<?php

namespace Tests\Feature\Fellowship;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\FellowshipResource\Pages\ViewFellowship;
use App\Filament\Resources\FellowshipResource\RelationManagers\FiltersRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\QuestionsRelationManager;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\FellowshipQuestionFormFields;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Authoring a Fellowship by hand, with no spreadsheet to import.
 *
 * The admin designates the Performance Topic classification on the Filters tab
 * — the same designation the first confirmed import would have made — and then
 * writes the bank's first question against it.
 */
class FellowshipManualAuthoringTest extends FellowshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    public function test_an_admin_designates_a_performance_filter_by_hand(): void
    {
        $fellowship = Fellowship::factory()->create(['name' => 'Fresh Bank']);

        $this->createFilter($fellowship, 'Performance Topic', true, ['Cardiology', 'Neurology'])
            ->assertHasNoActionErrors();

        /** @var FellowshipFilter $filter */
        $filter = $fellowship->filters()->sole();

        $this->assertTrue($filter->is_performance, 'The toggle must persist onto the filter.');
        $this->assertSame(
            $filter->getKey(),
            $fellowship->refresh()->performance_filter_id,
            'The fellowship must point at its designated classification.',
        );
        $this->assertEqualsCanonicalizing(
            ['Cardiology', 'Neurology'],
            $filter->values()->pluck('value')->all(),
        );
    }

    public function test_a_filter_created_without_the_toggle_stays_dynamic(): void
    {
        $fellowship = Fellowship::factory()->create();

        $this->createFilter($fellowship, 'System', false, ['Cardiovascular'])
            ->assertHasNoActionErrors();

        $filter = $fellowship->filters()->sole();

        $this->assertFalse($filter->is_performance);
        $this->assertNull($fellowship->refresh()->performance_filter_id);
        $this->assertTrue($fellowship->dynamicFilters()->whereKey($filter->getKey())->exists());
    }

    public function test_the_designation_may_be_turned_on_for_an_existing_filter(): void
    {
        $fellowship = Fellowship::factory()->create();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->editFilter($fellowship, $system, ['is_performance' => true])
            ->assertHasNoActionErrors();

        $this->assertTrue($system->refresh()->is_performance);
        $this->assertSame($system->getKey(), $fellowship->refresh()->performance_filter_id);
    }

    public function test_the_designation_moves_while_no_question_is_filed_under_it(): void
    {
        $fellowship = $this->fellowship();
        $original = $fellowship->performanceFilter;
        $replacement = $this->dynamicFilter($fellowship, 'Topic', ['Cardiology']);

        $this->editFilter($fellowship, $replacement, ['is_performance' => true])
            ->assertHasNoActionErrors();

        $this->assertSame($replacement->getKey(), $fellowship->refresh()->performance_filter_id);
        $this->assertFalse($original->refresh()->is_performance, 'A fellowship has one classification.');
        $this->assertSame(
            1,
            $fellowship->filters()->where('is_performance', true)->count(),
            'The designation must not be held by two filters at once.',
        );
    }

    public function test_the_designation_cannot_be_moved_away_from_classified_questions(): void
    {
        $fellowship = $this->fellowship();
        $original = $fellowship->performanceFilter;
        $this->question($fellowship, topic: 'Cardiology');

        $replacement = $this->dynamicFilter($fellowship, 'Topic', ['Neurology']);

        $this->editFilter($fellowship, $replacement, ['is_performance' => true])
            ->assertHasActionErrors(['is_performance']);

        $this->assertFalse($replacement->refresh()->is_performance);
        $this->assertSame(
            $original->getKey(),
            $fellowship->refresh()->performance_filter_id,
            'The existing configuration must not be overwritten.',
        );
    }

    public function test_the_designation_cannot_be_withdrawn_from_classified_questions(): void
    {
        $fellowship = $this->fellowship();
        $filter = $fellowship->performanceFilter;
        $this->question($fellowship, topic: 'Cardiology');

        $this->editFilter($fellowship, $filter, ['is_performance' => false])
            ->assertHasActionErrors(['is_performance']);

        $this->assertTrue($filter->refresh()->is_performance);
        $this->assertSame($filter->getKey(), $fellowship->refresh()->performance_filter_id);
    }

    public function test_the_designation_may_be_withdrawn_while_no_question_is_classified(): void
    {
        $fellowship = $this->fellowship();
        $filter = $fellowship->performanceFilter;

        $this->editFilter($fellowship, $filter, ['is_performance' => false])
            ->assertHasNoActionErrors();

        $this->assertFalse($filter->refresh()->is_performance);
        $this->assertNull($fellowship->refresh()->performance_filter_id);
    }

    public function test_the_first_question_is_written_by_hand_after_designating_the_filter(): void
    {
        $fellowship = Fellowship::factory()->create(['name' => 'Fresh Bank']);

        $this->createFilter($fellowship, 'Performance Topic', true, ['Cardiology', 'Neurology'])
            ->assertHasNoActionErrors();

        $cardiology = $fellowship->refresh()->performanceFilter->values()->where('value', 'Cardiology')->sole();

        $this->createQuestion($fellowship, [
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $cardiology->getKey(),
        ])->assertHasNoActionErrors();

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame('Which antiplatelet?', $question->question_text);
        $this->assertSame($cardiology->getKey(), $question->performance_filter_value_id);
        $this->assertSame('Cardiology', $question->performanceTopic->value);
        $this->assertSame('Aspirin', $question->options->firstWhere('is_correct', true)?->option_text);
    }

    public function test_a_manually_created_dynamic_filter_is_offered_on_the_question_form(): void
    {
        $fellowship = Fellowship::factory()->create();

        $this->createFilter($fellowship, 'Performance Topic', true, ['Cardiology'])->assertHasNoActionErrors();
        $this->createFilter($fellowship, 'System', false, ['Cardiovascular', 'Respiratory'])->assertHasNoActionErrors();

        $fellowship->refresh();
        $topic = $fellowship->performanceFilter->values()->sole();
        $system = $fellowship->dynamicFilters()->where('name', 'System')->sole();
        $respiratory = $system->values()->where('value', 'Respiratory')->sole();

        $this->createQuestion($fellowship, [
            'question_text' => 'Which inhaler?',
            'difficulty' => QuizDifficulty::Medium->value,
            'performance_filter_value_id' => $topic->getKey(),
            FellowshipQuestionFormFields::FILTER_FIELD_PREFIX.$system->getKey() => $respiratory->getKey(),
        ])->assertHasNoActionErrors();

        $question = $fellowship->questions()->sole();

        $this->assertSame(
            $respiratory->getKey(),
            $question->valueForFilter($system->getKey())?->fellowship_filter_value_id,
        );
    }

    public function test_question_authoring_is_blocked_until_the_classification_is_usable(): void
    {
        $fellowship = Fellowship::factory()->create();

        $this->assertSame(
            __('admin.fellowship.question_needs_performance_filter'),
            QuestionsRelationManager::authoringBlockedReason($fellowship),
        );

        $this->createFilter($fellowship, 'Performance Topic', true, [])->assertHasNoActionErrors();

        $this->assertSame(
            __('admin.fellowship.question_needs_performance_topic_value'),
            QuestionsRelationManager::authoringBlockedReason($fellowship->refresh()),
        );

        $fellowship->performanceFilter->values()->create(['value' => 'Cardiology']);

        $this->assertNull(QuestionsRelationManager::authoringBlockedReason($fellowship->refresh()));
    }

    public function test_an_invalid_manual_question_is_refused(): void
    {
        $fellowship = Fellowship::factory()->create();
        $this->createFilter($fellowship, 'Performance Topic', true, ['Cardiology'])->assertHasNoActionErrors();

        $topic = $fellowship->refresh()->performanceFilter->values()->sole();

        $this->createQuestion($fellowship, [
            'question_text' => 'No topic?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => null,
        ])->assertHasActionErrors(['performance_filter_value_id' => 'required']);

        $this->createQuestion($fellowship, [
            'question_text' => 'Only one option?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
        ], [['option_text' => 'Aspirin', 'is_correct' => true]])
            ->assertHasActionErrors(['options']);

        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_existing_filters_and_question_associations_survive_a_filter_edit(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $question = $this->question($fellowship, topic: 'Cardiology');
        $this->tagQuestion($question, $system, 'Cardiovascular');

        $topicId = $question->performance_filter_value_id;
        $systemValueId = $question->valueForFilter($system->getKey())?->fellowship_filter_value_id;

        $this->editFilter($fellowship, $system, ['name' => 'Body System'])
            ->assertHasNoActionErrors();

        $question->refresh()->load('filterValues');

        $this->assertSame('Body System', $system->refresh()->name);
        $this->assertFalse($system->is_performance);
        $this->assertSame($topicId, $question->performance_filter_value_id);
        $this->assertSame($systemValueId, $question->valueForFilter($system->getKey())?->fellowship_filter_value_id);
        $this->assertSame($fellowship->performanceFilter->getKey(), $fellowship->refresh()->performance_filter_id);
    }

    public function test_a_value_may_be_added_to_a_filter_whose_values_are_already_in_use(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');

        $existing = $system->values()->sole();

        $this->filtersTable($fellowship)
            ->mountAction(TestAction::make('edit')->table($system))
            ->set('mountedActions.0.data.values', [
                'record-'.$existing->getKey() => ['value' => 'Cardiovascular'],
                (string) Str::uuid() => ['value' => 'Respiratory'],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            ['Cardiovascular', 'Respiratory'],
            $system->refresh()->values()->pluck('value')->all(),
        );
        $this->assertSame(
            $existing->getKey(),
            $system->values()->where('value', 'Cardiovascular')->sole()->getKey(),
            'The value in use must keep its identity, or the questions filed under it are re-filed.',
        );
    }

    public function test_a_value_a_question_still_uses_cannot_be_removed(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');

        $this->filtersTable($fellowship)
            ->mountAction(TestAction::make('edit')->table($system))
            ->set('mountedActions.0.data.values', [
                (string) Str::uuid() => ['value' => 'Respiratory'],
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['values']);

        $this->assertSame(['Cardiovascular'], $system->refresh()->values()->pluck('value')->all());
    }

    /**
     * Mount and call the filters table's create action, the way an admin fills
     * it: a name, the Performance Topic toggle, and the values repeater.
     *
     * @param  list<string>  $values
     */
    private function createFilter(Fellowship $fellowship, string $name, bool $isPerformance, array $values)
    {
        $component = $this->filtersTable($fellowship)
            ->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.name', $name)
            ->set('mountedActions.0.data.is_performance', $isPerformance)
            ->set('mountedActions.0.data.values', $this->keyedValues($values));

        return $component->callMountedAction();
    }

    /**
     * Mount and call the edit action on one filter, changing only the given
     * fields and keeping the values it already has.
     *
     * @param  array<string, mixed>  $data
     */
    private function editFilter(Fellowship $fellowship, FellowshipFilter $filter, array $data)
    {
        $component = $this->filtersTable($fellowship)
            ->mountAction(TestAction::make('edit')->table($filter));

        // A value the repeater loaded from a record is keyed `record-<id>`;
        // re-stating them is what tells the form none is being removed.
        $component->set('mountedActions.0.data.values', $filter->values()
            ->get()
            ->mapWithKeys(fn ($value): array => ['record-'.$value->getKey() => ['value' => $value->value]])
            ->all());

        foreach ($data as $field => $value) {
            $component->set("mountedActions.0.data.{$field}", $value);
        }

        return $component->callMountedAction();
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
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
            ->set('mountedActions.0.data.options', $this->keyedOptions($options ?? [
                ['option_text' => 'Aspirin', 'is_correct' => true],
                ['option_text' => 'Warfarin', 'is_correct' => false],
            ]))
            ->callMountedAction();
    }

    /**
     * Repeater state is keyed by item; a fresh uuid key is a new row.
     *
     * @param  list<string>  $values
     * @return array<string, array<string, string>>
     */
    private function keyedValues(array $values): array
    {
        return collect($values)
            ->mapWithKeys(fn (string $value): array => [(string) Str::uuid() => ['value' => $value]])
            ->all();
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
