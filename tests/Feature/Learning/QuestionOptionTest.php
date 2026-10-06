<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\QuestionResource\Pages\EditQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

class QuestionOptionTest extends LearningTestCase
{
    /**
     * Repeater state is keyed by item UUID, so tests set the whole options
     * array rather than merging into the items already loaded from the record.
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
    private function saveOptions(Question $question, array $options)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuestion::class, ['record' => $question->getRouteKey()])
            ->set('data.options', $this->keyedOptions($options))
            ->call('save');
    }

    public function test_option_belongs_to_a_question(): void
    {
        $question = Question::factory()->create();
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);

        $this->assertTrue($option->question->is($question));
        $this->assertTrue($question->options->contains($option));
    }

    public function test_options_are_stored_as_rows_not_json_or_lettered_columns(): void
    {
        $this->assertTrue(Schema::hasTable('question_options'));

        foreach (['option_a', 'option_b', 'option_c', 'option_d', 'options'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('questions', $column),
                "questions must not carry an [{$column}] column.",
            );
        }
    }

    public function test_a_question_requires_at_least_two_options(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'Only option', 'is_correct' => true],
        ])->assertHasFormErrors(['options']);

        $this->assertSame(2, $question->refresh()->options()->count());
    }

    public function test_zero_correct_answers_is_rejected(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'First', 'is_correct' => false],
            ['option_text' => 'Second', 'is_correct' => false],
        ])->assertHasFormErrors(['options']);

        $this->assertDatabaseMissing('question_options', ['option_text' => 'First']);
    }

    public function test_multiple_correct_answers_are_rejected(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'First', 'is_correct' => true],
            ['option_text' => 'Second', 'is_correct' => true],
        ])->assertHasFormErrors(['options']);

        $this->assertDatabaseMissing('question_options', ['option_text' => 'First']);
    }

    public function test_exactly_one_correct_answer_is_accepted(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'Correct one', 'is_correct' => true],
            ['option_text' => 'Wrong one', 'is_correct' => false],
        ])->assertHasNoFormErrors();

        $question->refresh()->unsetRelation('options');

        $this->assertSame(2, $question->options()->count());
        $this->assertSame('Correct one', $question->correctOption()?->option_text);
    }

    public function test_option_text_is_required(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => null, 'is_correct' => true],
            ['option_text' => 'Second', 'is_correct' => false],
        ])->assertHasFormErrors();

        $this->assertDatabaseMissing('question_options', ['option_text' => 'Second']);
    }

    public function test_option_order_is_preserved(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'First', 'is_correct' => false],
            ['option_text' => 'Second', 'is_correct' => true],
            ['option_text' => 'Third', 'is_correct' => false],
        ])->assertHasNoFormErrors();

        $question->refresh()->unsetRelation('options');

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $question->options()->pluck('option_text')->all(),
        );
        $this->assertSame([1, 2, 3], $question->options()->pluck('order')->all());
    }

    public function test_options_can_be_added(): void
    {
        $question = Question::factory()->withOptions(2)->create();

        $this->saveOptions($question, [
            ['option_text' => 'A', 'is_correct' => true],
            ['option_text' => 'B', 'is_correct' => false],
            ['option_text' => 'C', 'is_correct' => false],
            ['option_text' => 'D', 'is_correct' => false],
        ])->assertHasNoFormErrors();

        $this->assertSame(4, $question->refresh()->options()->count());
    }

    public function test_options_can_be_edited(): void
    {
        $question = Question::factory()->create();
        QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'option_text' => 'Old right', 'order' => 1]);
        QuestionOption::factory()->create(['question_id' => $question->id, 'option_text' => 'Old wrong', 'order' => 2]);

        $this->saveOptions($question, [
            ['option_text' => 'New right', 'is_correct' => true],
            ['option_text' => 'New wrong', 'is_correct' => false],
        ])->assertHasNoFormErrors();

        $question->refresh()->unsetRelation('options');

        $this->assertSame(
            ['New right', 'New wrong'],
            $question->options()->pluck('option_text')->all(),
        );
        $this->assertDatabaseMissing('question_options', ['option_text' => 'Old right']);
    }

    public function test_options_can_be_removed_while_the_constraints_still_hold(): void
    {
        $question = Question::factory()->withOptions(4)->create();
        $this->assertSame(4, $question->options()->count());

        $this->saveOptions($question, [
            ['option_text' => 'Kept right', 'is_correct' => true],
            ['option_text' => 'Kept wrong', 'is_correct' => false],
        ])->assertHasNoFormErrors();

        $this->assertSame(2, $question->refresh()->options()->count());
    }

    public function test_option_order_is_assigned_sequentially_within_its_question(): void
    {
        $question = Question::factory()->create();
        $otherQuestion = Question::factory()->create();

        $first = QuestionOption::factory()->create(['question_id' => $question->id]);
        $second = QuestionOption::factory()->create(['question_id' => $question->id]);
        $otherFirst = QuestionOption::factory()->create(['question_id' => $otherQuestion->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);
        $this->assertSame(1, $otherFirst->order);
    }
}
