<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\QuizDifficulty;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipQuestionWriter;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;

/**
 * Session snapshots are immutable.
 *
 * Whatever an admin does to a question afterwards — reword it, replace its
 * options, move the correct answer, re-file its topic, delete it outright — a
 * session already generated must show and score exactly what it did before.
 */
class FellowshipSnapshotImmutabilityTest extends StudentFellowshipTestCase
{
    public function test_rewording_a_question_does_not_change_a_generated_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, text: 'Original wording?');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $question->update(['question_text' => 'Completely different wording?']);

        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.question_text', 'Original wording?');
    }

    public function test_replacing_the_options_does_not_change_a_generated_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, options: 2);
        $originalOptions = $question->options->pluck('option_text')->all();

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $question->options()->delete();
        $question->options()->createMany([
            ['option_text' => 'Brand new A', 'is_correct' => true, 'order' => 1],
            ['option_text' => 'Brand new B', 'is_correct' => false, 'order' => 2],
        ]);

        $served = collect(
            $this->getJson($this->sessionsUrl."/{$session->id}")->assertOk()->json('data.questions.0.options')
        )->pluck('option_text')->all();

        $this->assertSame($originalOptions, $served);
    }

    public function test_moving_the_correct_answer_does_not_change_how_a_session_grades(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, options: 2);
        $originalCorrect = $question->options->firstWhere('is_correct', true);
        $originalWrong = $question->options->firstWhere('is_correct', false);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        // The admin swaps which option is correct.
        $originalCorrect->update(['is_correct' => false]);
        $originalWrong->update(['is_correct' => true]);

        // The session still grades against what it captured.
        $sessionQuestion = $session->questions()->sole();

        $this->answer($session, $sessionQuestion->getKey(), (string) $originalCorrect->getKey())
            ->assertOk()
            ->assertJsonPath('data.question.is_correct', true);
    }

    public function test_refiling_a_questions_topic_does_not_move_a_sessions_history(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'Cardiology');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        $question->update([
            'performance_filter_value_id' => $this->topicValue($fellowship, 'Neurology')->getKey(),
        ]);

        $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertOk()
            ->assertJsonPath('data.topic_performance.0.topic_value', 'Cardiology');

        $this->assertSame(1, app(FellowshipAnalyticsService::class)->topicsCompleted($student, $fellowship));
    }

    public function test_changing_the_difficulty_does_not_change_a_sessions_quiz_performance(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, difficulty: QuizDifficulty::Easy);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        $question->update(['difficulty' => QuizDifficulty::Hard]);

        $levels = collect($this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk()->json('data.quiz_performance'))
            ->keyBy('difficulty');

        $this->assertSame(1, $levels['easy']['answered_questions']);
        $this->assertSame(0, $levels['hard']['answered_questions']);
    }

    public function test_soft_deleting_a_question_leaves_a_session_answerable(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, text: 'Soon to be deleted?');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $question->delete();

        $sessionQuestion = $session->questions()->sole();

        $this->answer($session, $sessionQuestion->getKey(), (string) $sessionQuestion->correctOptionKey())
            ->assertOk()
            ->assertJsonPath('data.question.question_text', 'Soon to be deleted?')
            ->assertJsonPath('data.question.is_correct', true);
    }

    public function test_force_deleting_a_question_leaves_the_session_intact(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, text: 'Gone entirely?');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        $question->forceDelete();

        $response = $this->getJson($this->sessionsUrl."/{$session->id}/result")->assertOk();

        $response->assertJsonPath('data.questions.0.question_text', 'Gone entirely?');
        $response->assertJsonPath('data.questions.0.is_correct', true);
        // The traceability link is dropped; the snapshot is not.
        $response->assertJsonPath('data.questions.0.fellowship_question_id', null);
        $response->assertJsonPath('data.average_score', 100);
    }

    public function test_deleting_a_question_does_not_disturb_an_earlier_sessions_score(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session, correct: 1);

        $before = app(FellowshipAnalyticsService::class)->averageScore($student, $fellowship);

        $fellowship->questions()->get()->each(fn ($question) => $question->delete());

        $this->assertSame($before, app(FellowshipAnalyticsService::class)->averageScore($student, $fellowship));
        $this->assertSame(50.0, $before);
    }

    public function test_an_import_updating_a_question_does_not_change_a_generated_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, text: 'Which drug?', options: 2);
        $originalCorrect = $question->options->firstWhere('is_correct', true)->option_text;

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        // An admin re-imports the same question with a different answer set and
        // chooses Update.
        $writer = app(FellowshipQuestionWriter::class);
        $map = ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);

        $writer->update(
            $fellowship,
            $question,
            ImportRowPayload::make(
                questionText: 'Which drug? (revised)',
                explanation: 'New reason.',
                difficulty: 'hard',
                correctAnswer: 'Rewritten B',
                performanceTopic: 'Neurology',
                options: ['Rewritten A', 'Rewritten B'],
                filters: [],
            ),
            $map,
        );

        $response = $this->getJson($this->sessionsUrl."/{$session->id}")->assertOk();

        $response->assertJsonPath('data.questions.0.question_text', 'Which drug?');
        $response->assertJsonPath('data.questions.0.performance_topic.value', 'Cardiology');

        $served = collect($response->json('data.questions.0.options'))->pluck('option_text')->all();
        $this->assertContains($originalCorrect, $served);
        $this->assertNotContains('Rewritten A', $served);
    }

    public function test_the_snapshot_itself_is_not_rewritten_by_answering(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $before = $session->questions()->sole()->question_snapshot;

        $this->answerCurrent($session)->assertOk();

        $this->assertSame($before, $session->refresh()->questions()->sole()->question_snapshot);
    }
}
