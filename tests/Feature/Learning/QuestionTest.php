<?php

namespace Tests\Feature\Learning;

use App\Exceptions\Learning\LastQuestionException;
use App\Filament\Resources\QuestionResource\Pages\EditQuestion;
use App\Filament\Resources\QuestionResource\Pages\ViewQuestion;
use App\Filament\Resources\QuizResource\Pages\ViewQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Models\Chapter;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\Subject;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

class QuestionTest extends LearningTestCase
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
    private function createQuestion(Quiz $quiz, ?string $question, array $options)
    {
        return $this->questionsTable($quiz)
            ->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.question', $question)
            ->set('mountedActions.0.data.options', $this->keyedOptions($options))
            ->callMountedAction();
    }

    /** @return array<int, array<string, mixed>> */
    private function validOptions(): array
    {
        return [
            ['option_text' => 'Right answer', 'is_correct' => true],
            ['option_text' => 'Wrong answer', 'is_correct' => false],
        ];
    }

    public function test_question_belongs_to_a_quiz(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);

        $this->assertTrue($question->quiz->is($quiz));
        $this->assertTrue($quiz->questions->contains($question));
    }

    public function test_question_carries_no_subject_chapter_or_difficulty_column(): void
    {
        $this->assertFalse(Schema::hasColumn('questions', 'subject_id'));
        $this->assertFalse(Schema::hasColumn('questions', 'chapter_id'));
        $this->assertFalse(Schema::hasColumn('questions', 'difficulty'));
    }

    public function test_question_can_be_created_from_the_quiz_with_quiz_id_assigned_automatically(): void
    {
        $quiz = Quiz::factory()->create();

        $this->createQuestion($quiz, 'Which bone is the longest?', $this->validOptions())
            ->assertHasNoActionErrors();

        $question = Question::query()->where('question', 'Which bone is the longest?')->sole();

        $this->assertSame($quiz->id, $question->quiz_id);
        $this->assertSame(2, $question->options()->count());
    }

    public function test_question_text_is_required(): void
    {
        $quiz = Quiz::factory()->create();

        $this->createQuestion($quiz, null, $this->validOptions())
            ->assertHasActionErrors(['question' => 'required']);
    }

    public function test_quiz_only_shows_its_own_questions(): void
    {
        $quiz = Quiz::factory()->create();
        $otherQuiz = Quiz::factory()->create();

        $own = Question::factory()->create(['quiz_id' => $quiz->id]);
        $foreign = Question::factory()->create(['quiz_id' => $otherQuiz->id]);

        $this->questionsTable($quiz)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_question_can_be_edited(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->fillForm(['question' => 'Reworded question?'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Reworded question?', $question->refresh()->question);
    }

    public function test_questions_table_shows_option_count_and_correct_answer(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);
        QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'option_text' => 'Femur', 'order' => 1]);
        QuestionOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Tibia', 'order' => 2]);

        $this->questionsTable($quiz)
            ->assertCanSeeTableRecords([$question])
            ->assertTableColumnStateSet('options_count', 2, $question)
            ->assertTableColumnStateSet('correct_answer', 'Femur', $question);
    }

    public function test_question_view_shows_its_quiz_chapter_subject_and_correct_option(): void
    {
        $subject = Subject::factory()->create(['name' => 'Osteology']);
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id, 'title' => 'Long Bones']);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Long Bones Quiz']);
        $question = Question::factory()->create(['quiz_id' => $quiz->id, 'question' => 'Longest bone?']);
        QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'option_text' => 'Femur', 'order' => 1]);
        QuestionOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Radius', 'order' => 2]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewQuestion::class, ['record' => $question->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('question-overview')
            ->assertSchemaComponentExists('question-options')
            ->assertSee('Longest bone?')
            ->assertSee('Long Bones Quiz')
            ->assertSee('Long Bones')
            ->assertSee('Osteology')
            ->assertSee('Femur')
            ->assertSee('Radius');
    }

    public function test_question_exposes_its_single_correct_option(): void
    {
        $question = Question::factory()->create();
        QuestionOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Nope', 'order' => 1]);
        QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'option_text' => 'Yes', 'order' => 2]);

        $this->assertSame('Yes', $question->refresh()->correctOption()?->option_text);
    }

    public function test_the_first_question_can_be_created_on_an_empty_quiz(): void
    {
        $quiz = Quiz::factory()->create();

        $this->assertFalse($quiz->hasQuestions());

        $this->createQuestion($quiz, 'First question?', $this->validOptions())
            ->assertHasNoActionErrors();

        $this->assertTrue($quiz->refresh()->hasQuestions());
        $this->assertSame(1, $quiz->questions()->count());
    }

    public function test_multiple_questions_can_be_created_on_one_quiz(): void
    {
        $quiz = Quiz::factory()->create();

        $this->createQuestion($quiz, 'First question?', $this->validOptions())->assertHasNoActionErrors();
        $this->createQuestion($quiz, 'Second question?', $this->validOptions())->assertHasNoActionErrors();

        $this->assertSame(2, $quiz->questions()->count());
    }

    public function test_a_question_can_be_deleted_while_the_quiz_keeps_more_than_one(): void
    {
        $quiz = Quiz::factory()->create();
        $first = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);
        $second = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        $this->questionsTable($quiz)
            ->callAction(TestAction::make('delete')->table($first));

        $this->assertDatabaseMissing('questions', ['id' => $first->id]);
        $this->assertDatabaseHas('questions', ['id' => $second->id]);
    }

    public function test_the_delete_action_disappears_once_only_one_question_is_left(): void
    {
        $quiz = Quiz::factory()->create();
        $only = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        $this->questionsTable($quiz)
            ->assertActionHidden(TestAction::make('delete')->table($only));

        $this->assertFalse($only->canBeDeleted());
    }

    public function test_deleting_the_last_remaining_question_is_rejected_server_side(): void
    {
        $quiz = Quiz::factory()->create();
        $only = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        // Bypasses the hidden Filament button entirely: this is the supported
        // domain path, and the model must still refuse.
        try {
            $only->delete();
            $this->fail('Expected deleting the last question to be rejected.');
        } catch (LastQuestionException $exception) {
            $this->assertSame('A quiz must contain at least one question.', $exception->getMessage());
        }

        $this->assertDatabaseHas('questions', ['id' => $only->id]);
        $this->assertSame(1, $quiz->questions()->count());
    }

    public function test_the_rejection_message_is_the_approved_business_wording(): void
    {
        $this->assertSame(
            'A quiz must contain at least one question.',
            LastQuestionException::cannotBeDeleted()->getMessage(),
        );

        $this->assertSame(
            'A quiz must contain at least one question.',
            __('admin.learning.last_question_cannot_be_deleted'),
        );
    }

    public function test_the_guard_releases_as_soon_as_a_second_question_exists(): void
    {
        $quiz = Quiz::factory()->create();
        $first = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        $this->assertFalse($first->canBeDeleted());

        Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        $this->assertTrue($first->refresh()->canBeDeleted());
        $this->assertTrue($first->delete());
        $this->assertDatabaseMissing('questions', ['id' => $first->id]);
    }

    public function test_the_questions_table_offers_no_bulk_delete(): void
    {
        $quiz = Quiz::factory()->create();
        Question::factory()->count(3)->withOptions(2)->create(['quiz_id' => $quiz->id]);

        $bulkActionNames = collect(
            $this->questionsTable($quiz)->instance()->getTable()->getFlatBulkActions(),
        )->map(fn (object $action): string => $action->getName())->all();

        $this->assertNotContains('delete', $bulkActionNames);
        $this->assertSame([], $bulkActionNames);
    }

    // ─── Ordering ───────────────────────────────────────────────────────────

    public function test_questions_carry_an_order_column(): void
    {
        $this->assertTrue(Schema::hasColumn('questions', 'order'));
        $this->assertContains('order', (new Question)->getFillable());
    }

    public function test_question_order_is_assigned_sequentially_within_its_quiz(): void
    {
        $quiz = Quiz::factory()->create();
        $otherQuiz = Quiz::factory()->create();

        $first = Question::factory()->create(['quiz_id' => $quiz->id]);
        $second = Question::factory()->create(['quiz_id' => $quiz->id]);
        $otherFirst = Question::factory()->create(['quiz_id' => $otherQuiz->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);
        $this->assertSame(1, $otherFirst->order);
    }

    public function test_questions_are_returned_in_order(): void
    {
        $quiz = Quiz::factory()->create();

        $third = Question::factory()->create(['quiz_id' => $quiz->id, 'question' => 'Third?', 'order' => 3]);
        $first = Question::factory()->create(['quiz_id' => $quiz->id, 'question' => 'First?', 'order' => 1]);
        $second = Question::factory()->create(['quiz_id' => $quiz->id, 'question' => 'Second?', 'order' => 2]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $quiz->questions()->pluck('id')->all(),
        );
    }

    public function test_the_questions_table_is_reorderable_by_drag_and_drop(): void
    {
        $quiz = Quiz::factory()->create();

        $this->assertSame('order', $this->questionsTable($quiz)->instance()->getTable()->getReorderColumn());
    }

    public function test_dragging_a_question_row_persists_the_new_order(): void
    {
        $quiz = Quiz::factory()->create();
        $one = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id, 'order' => 1]);
        $two = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id, 'order' => 2]);
        $three = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id, 'order' => 3]);

        $this->questionsTable($quiz)
            ->call('reorderTable', [$three->getKey(), $one->getKey(), $two->getKey()]);

        $this->assertSame(1, $three->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);
        $this->assertSame(3, $two->refresh()->order);

        $this->assertSame(
            [$three->id, $one->id, $two->id],
            $quiz->questions()->pluck('id')->all(),
        );
    }

    public function test_reordering_one_quizs_questions_never_touches_another_quiz(): void
    {
        $quiz = Quiz::factory()->create();
        $otherQuiz = Quiz::factory()->create();

        $one = Question::factory()->create(['quiz_id' => $quiz->id, 'order' => 1]);
        $two = Question::factory()->create(['quiz_id' => $quiz->id, 'order' => 2]);

        $otherOne = Question::factory()->create(['quiz_id' => $otherQuiz->id, 'order' => 1]);
        $otherTwo = Question::factory()->create(['quiz_id' => $otherQuiz->id, 'order' => 2]);

        $this->questionsTable($quiz)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $two->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);

        $this->assertSame(1, $otherOne->refresh()->order);
        $this->assertSame(2, $otherTwo->refresh()->order);
    }

    public function test_the_question_form_never_asks_for_a_manual_order(): void
    {
        $relationManager = new QuestionsRelationManager;

        // Top-level only: descending into the options repeater would try to
        // resolve its relationship against a record that does not exist here.
        $componentNames = collect($relationManager->form(FilamentSchema::make($relationManager))->getComponents(withHidden: true))
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['question', 'options'], $componentNames);
    }

    public function test_the_question_edit_page_hides_delete_for_the_last_question(): void
    {
        $quiz = Quiz::factory()->create();
        $only = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $only->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('delete');

        $second = Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $second->getRouteKey()])
            ->assertOk()
            ->assertActionVisible('delete');
    }
}
