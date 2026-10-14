<?php

namespace Tests\Feature\Study;

use App\Models\Chapter;
use App\Models\Question;
use App\Models\QuizAttemptQuestion;
use Laravel\Sanctum\Sanctum;

/**
 * What the student API gives away about a chapter-quiz question, and when.
 *
 * The question's title travels with it from the moment the attempt is created.
 * The per-option explanations do not: saying why an option is right names the
 * correct answer, so they are withheld exactly as `correct_option_key` is —
 * until that question has been answered.
 *
 * Everything the student sees comes from the attempt's own snapshot, so an
 * admin editing or deleting the question afterwards changes nothing.
 */
class ChapterQuizTitleAndOptionExplanationTest extends StudyTestCase
{
    /**
     * A published chapter whose quiz holds one titled question with two
     * explained options.
     */
    private function chapterWithExplainedQuestion(): Chapter
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();

        /** @var Question $question */
        $question = $chapter->quiz->questions()->sole();
        $question->update(['title' => 'Deltoid innervation']);

        $options = $question->options;
        $options[0]->update(['explanation' => 'Correct: the axillary nerve supplies it.']);
        $options[1]->update(['explanation' => 'Incorrect: the radial nerve does not.']);

        return $chapter->refresh();
    }

    public function test_the_chapter_api_returns_its_references(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();
        $chapter->update(['references' => 'Gray\'s Anatomy, 42nd ed.']);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.references', 'Gray\'s Anatomy, 42nd ed.');
    }

    public function test_a_chapter_without_references_returns_null(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.references', null);
    }

    public function test_the_question_title_is_returned_with_the_attempt(): void
    {
        $chapter = $this->chapterWithExplainedQuestion();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertCreated()
            ->assertJsonPath('data.questions.0.title', 'Deltoid innervation')
            ->assertJsonPath('data.questions.0.is_answered', false);
    }

    public function test_an_untitled_question_returns_a_null_title_rather_than_omitting_it(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertCreated()
            ->assertJsonPath('data.questions.0.title', null);
    }

    public function test_an_unanswered_question_reveals_no_option_explanation_and_no_correct_answer(): void
    {
        $chapter = $this->chapterWithExplainedQuestion();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $question = $attempt['questions'][0];

        $this->assertNull($question['correct_option_key']);
        $this->assertNull($question['is_correct']);
        $this->assertNull($question['selected_option_explanation']);

        foreach ($question['options'] as $option) {
            $this->assertSame(['key', 'option_text'], array_keys($option));
        }

        // And nowhere else in the payload either.
        $body = json_encode($attempt);
        $this->assertStringNotContainsString('Correct: the axillary nerve supplies it.', (string) $body);
        $this->assertStringNotContainsString('Incorrect: the radial nerve does not.', (string) $body);
    }

    public function test_answering_reveals_every_option_explanation_and_which_one_was_correct(): void
    {
        $chapter = $this->chapterWithExplainedQuestion();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $correctKey = (string) $attemptQuestion->correctOptionKey();

        $question = $this->answer($attempt['id'], $attemptQuestion->id, $correctKey)
            ->assertOk()
            ->json('data.question');

        $this->assertTrue($question['is_answered']);
        $this->assertSame($correctKey, $question['correct_option_key']);
        $this->assertSame(
            'Correct: the axillary nerve supplies it.',
            $question['selected_option_explanation'],
        );

        $explanations = array_column($question['options'], 'explanation', 'key');
        $this->assertContains('Correct: the axillary nerve supplies it.', $explanations);
        $this->assertContains('Incorrect: the radial nerve does not.', $explanations);

        // Correctness is now on the options too, and exactly one of them.
        $this->assertSame(1, count(array_filter(array_column($question['options'], 'is_correct'))));
    }

    public function test_the_snapshot_keeps_the_title_and_explanations_after_the_question_is_rewritten(): void
    {
        $chapter = $this->chapterWithExplainedQuestion();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        /** @var Question $question */
        $question = $chapter->quiz->questions()->sole();
        $question->update(['title' => 'Rewritten heading']);
        $question->options->each->update(['explanation' => 'Rewritten reason']);

        $snapshot = $attemptQuestion->refresh()->snapshot();

        $this->assertSame('Deltoid innervation', $snapshot->title);
        $this->assertSame(
            ['Correct: the axillary nerve supplies it.', 'Incorrect: the radial nerve does not.'],
            array_slice(array_column($snapshot->revealedOptions(), 'explanation'), 0, 2),
        );
    }

    public function test_the_snapshot_survives_the_question_being_deleted(): void
    {
        $chapter = $this->chapterWithExplainedQuestion();
        // A quiz must keep one question, so a second one makes the first
        // deletable.
        Question::factory()->withOptions(2)->create(['quiz_id' => $chapter->quiz->getKey()]);

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = collect($attempt['questions'])->firstWhere('title', 'Deltoid innervation');
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($row['id']);

        $chapter->quiz->questions()->whereKey($attemptQuestion->question_id)->first()->delete();

        $snapshot = $attemptQuestion->refresh()->snapshot();

        $this->assertSame('Deltoid innervation', $snapshot->title);
        $this->assertSame(
            'Correct: the axillary nerve supplies it.',
            $snapshot->optionExplanation((string) $snapshot->correctOptionKey()),
        );
    }

    public function test_an_attempt_snapshotted_before_these_fields_stays_readable_and_answerable(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        // Exactly the document shape attempts carried before titles and
        // per-option explanations existed.
        $legacy = $attemptQuestion->question_snapshot;
        unset($legacy['title']);
        $legacy['version'] = 1;
        $legacy['options'] = array_map(static function (array $option): array {
            unset($option['explanation']);

            return $option;
        }, $legacy['options']);
        $attemptQuestion->forceFill(['question_snapshot' => $legacy])->save();

        $snapshot = $attemptQuestion->refresh()->snapshot();
        $this->assertNull($snapshot->title);
        $this->assertSame([null, null, null, null], array_column($snapshot->revealedOptions(), 'explanation'));

        $this->answer($attempt['id'], $attemptQuestion->id, (string) $snapshot->correctOptionKey())
            ->assertOk()
            ->assertJsonPath('data.question.title', null)
            ->assertJsonPath('data.question.is_correct', true)
            ->assertJsonPath('data.question.selected_option_explanation', null);
    }
}
