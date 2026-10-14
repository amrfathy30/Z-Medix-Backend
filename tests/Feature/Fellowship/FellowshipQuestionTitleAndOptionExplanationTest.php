<?php

namespace Tests\Feature\Fellowship;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\FellowshipResource\Pages\ViewFellowship;
use App\Filament\Resources\FellowshipResource\RelationManagers\QuestionsRelationManager;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\FellowshipQuestionFormFields;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A Fellowship question's optional title and the explanation each of its answer
 * options may carry, as the admin writes them by hand.
 *
 * Both are optional, the question's own explanation is untouched, and the
 * single-correct-answer rule is unchanged: an explanation is prose beside an
 * option, never a second way of marking one correct.
 */
class FellowshipQuestionTitleAndOptionExplanationTest extends FellowshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->superAdmin(), 'admin');
    }

    private function questionsTable(Fellowship $fellowship)
    {
        return Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $fellowship,
            'pageClass' => ViewFellowship::class,
        ]);
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

    /**
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
            ->set('mountedActions.0.data.options', $this->keyedOptions($options ?? [
                ['option_text' => 'Aspirin', 'is_correct' => true],
                ['option_text' => 'Warfarin', 'is_correct' => false],
            ]))
            ->callMountedAction();
    }

    public function test_a_question_is_written_with_a_title_and_an_explanation_per_option(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');

        $this->createQuestion($fellowship, [
            'title' => 'Antiplatelet choice',
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
            'explanation' => 'Question-level reasoning.',
        ], [
            ['option_text' => 'Aspirin', 'is_correct' => true, 'explanation' => 'Correct: first line.'],
            ['option_text' => 'Warfarin', 'is_correct' => false, 'explanation' => 'Incorrect: an anticoagulant.'],
        ])->assertHasNoActionErrors();

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame('Antiplatelet choice', $question->title);
        $this->assertSame('Which antiplatelet?', $question->question_text);
        // The question's own explanation is preserved alongside, not replaced.
        $this->assertSame('Question-level reasoning.', $question->explanation);
        $this->assertSame(
            ['Correct: first line.', 'Incorrect: an anticoagulant.'],
            $question->options->pluck('explanation')->all(),
        );
        $this->assertSame(1, $question->options->where('is_correct', true)->count());
    }

    public function test_the_title_and_the_option_explanations_are_optional(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');

        $this->createQuestion($fellowship, [
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
        ])->assertHasNoActionErrors();

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertNull($question->title);
        $this->assertSame([null, null], $question->options->pluck('explanation')->all());
    }

    public function test_every_answer_option_has_its_own_explanation_field(): void
    {
        $names = collect(FellowshipQuestionFormFields::optionsRepeater()->getDefaultChildComponents())
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['option_text', 'is_correct', 'explanation'], $names);
    }

    public function test_the_question_form_offers_a_title_field(): void
    {
        $fellowship = $this->fellowship();

        $names = collect(FellowshipQuestionFormFields::for($fellowship))
            ->flatMap(fn (object $section): array => $section->getDefaultChildComponents())
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('title', $names);
        $this->assertContains('question_text', $names);
    }

    public function test_an_existing_question_is_edited_to_add_a_title_and_option_explanations(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, text: 'Which drug?', options: 2);

        $this->assertNull($question->title);

        $this->questionsTable($fellowship)
            ->mountAction(TestAction::make('edit')->table($question))
            ->set('mountedActions.0.data.title', 'Drug choice')
            ->set('mountedActions.0.data.options', $this->keyedOptions(
                $question->options
                    ->map(fn ($option): array => [
                        'option_text' => $option->option_text,
                        'is_correct' => (bool) $option->is_correct,
                        'explanation' => $option->is_correct ? 'Right.' : 'Wrong.',
                    ])
                    ->all(),
            ))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $question->refresh()->load('options');

        $this->assertSame('Drug choice', $question->title);
        $this->assertSame(['Right.', 'Wrong.'], $question->options->pluck('explanation')->all());
        $this->assertSame(1, $question->options->where('is_correct', true)->count());
    }

    public function test_a_title_longer_than_the_column_is_refused(): void
    {
        $fellowship = $this->fellowship();
        $topic = $this->topicValue($fellowship, 'Cardiology');

        $this->createQuestion($fellowship, [
            'title' => str_repeat('a', 256),
            'question_text' => 'Which antiplatelet?',
            'difficulty' => QuizDifficulty::Easy->value,
            'performance_filter_value_id' => $topic->getKey(),
        ])->assertHasActionErrors(['title' => 'max']);

        $this->assertSame(0, $fellowship->questions()->count());
    }

    public function test_the_title_shows_in_the_questions_table(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, text: 'Which drug?');
        $question->update(['title' => 'Drug choice']);

        $this->questionsTable($fellowship)
            ->assertTableColumnStateSet('title', 'Drug choice', $question);
    }
}
