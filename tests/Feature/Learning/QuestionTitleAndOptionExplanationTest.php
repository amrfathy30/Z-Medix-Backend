<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\QuestionResource;
use App\Filament\Resources\QuestionResource\Pages\EditQuestion;
use App\Filament\Resources\QuestionResource\Pages\ViewQuestion;
use App\Filament\Resources\QuizResource\Pages\ViewQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Models\Question;
use App\Models\Quiz;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A chapter-quiz question's optional title, and the explanation each of its
 * answer options may carry.
 *
 * Both are optional and neither replaces anything: the question text stays
 * required, the question's own explanation is untouched, and a question written
 * before either field existed is still valid and editable.
 */
class QuestionTitleAndOptionExplanationTest extends LearningTestCase
{
    private function questionsTable(Quiz $quiz)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(QuestionsRelationManager::class, [
                'ownerRecord' => $quiz,
                'pageClass' => ViewQuiz::class,
            ]);
    }

    /**
     * Repeater state is keyed by item UUID, so tests set the whole options
     * array rather than merging into the default blank items.
     *
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
     * @param  array<int, array<string, mixed>>  $options
     */
    private function createQuestion(Quiz $quiz, ?string $title, array $options)
    {
        return $this->questionsTable($quiz)
            ->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.title', $title)
            ->set('mountedActions.0.data.question', 'Which nerve supplies the deltoid?')
            ->set('mountedActions.0.data.options', $this->keyedOptions($options))
            ->callMountedAction();
    }

    public function test_a_question_is_created_with_a_title_and_per_option_explanations(): void
    {
        $quiz = Quiz::factory()->create();

        $this->createQuestion($quiz, 'Deltoid innervation', [
            [
                'option_text' => 'Axillary',
                'is_correct' => true,
                'explanation' => 'The axillary nerve supplies the deltoid.',
            ],
            [
                'option_text' => 'Radial',
                'is_correct' => false,
                'explanation' => 'The radial nerve supplies the extensors, not the deltoid.',
            ],
        ])->assertHasNoActionErrors();

        /** @var Question $question */
        $question = $quiz->questions()->with('options')->sole();

        $this->assertSame('Deltoid innervation', $question->title);
        $this->assertSame('Which nerve supplies the deltoid?', $question->question);
        $this->assertSame(
            ['The axillary nerve supplies the deltoid.', 'The radial nerve supplies the extensors, not the deltoid.'],
            $question->options->pluck('explanation')->all(),
        );
    }

    public function test_the_title_and_option_explanations_are_optional(): void
    {
        $quiz = Quiz::factory()->create();

        $this->createQuestion($quiz, null, [
            ['option_text' => 'Axillary', 'is_correct' => true],
            ['option_text' => 'Radial', 'is_correct' => false],
        ])->assertHasNoActionErrors();

        /** @var Question $question */
        $question = $quiz->questions()->with('options')->sole();

        $this->assertNull($question->title);
        $this->assertSame([null, null], $question->options->pluck('explanation')->all());
    }

    public function test_every_answer_option_has_its_own_explanation_field(): void
    {
        $names = collect(QuestionResource::optionsRepeater()->getDefaultChildComponents())
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['option_text', 'is_correct', 'explanation'], $names);
    }

    public function test_an_existing_question_without_either_field_stays_editable(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('title')
            ->fillForm(['title' => 'Added later'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Added later', $question->refresh()->title);
    }

    public function test_an_option_explanation_is_saved_from_the_edit_form(): void
    {
        $question = Question::factory()->withOptions(2)->create();
        $options = $question->options;

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->assertOk()
            ->set('data.options', $this->keyedOptions([
                [
                    'option_text' => $options[0]->option_text,
                    'is_correct' => (bool) $options[0]->is_correct,
                    'explanation' => 'Right because of this.',
                ],
                [
                    'option_text' => $options[1]->option_text,
                    'is_correct' => (bool) $options[1]->is_correct,
                    'explanation' => 'Wrong because of that.',
                ],
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['Right because of this.', 'Wrong because of that.'],
            $question->refresh()->options->pluck('explanation')->all(),
        );
    }

    public function test_a_title_longer_than_the_column_is_refused(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->fillForm(['title' => str_repeat('a', 256)])
            ->call('save')
            ->assertHasFormErrors(['title' => 'max']);

        $this->assertNull($question->refresh()->title);
    }

    public function test_the_view_page_shows_the_title_and_the_option_explanations(): void
    {
        $question = Question::factory()->withOptions(2)->create(['title' => 'Shown heading']);
        $question->options->first()->update(['explanation' => 'Shown option reason']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewQuestion::class, ['record' => $question->getRouteKey()])
            ->assertOk()
            ->assertSee('Shown heading')
            ->assertSee('Shown option reason');
    }
}
