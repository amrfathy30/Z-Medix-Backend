<?php

namespace Tests\Feature\Study;

use App\Enums\AccountStatus;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\StudentChapterProgress;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared setup for the student study flow: a published subject with chapters
 * that each own a quiz with answerable questions, which is the shape the admin
 * module guarantees for published content.
 */
abstract class StudyTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $studyUrl = '/api/student/study';

    protected function student(): User
    {
        return User::factory()->create(['status' => AccountStatus::Active]);
    }

    /**
     * A published subject whose chapters are published, each with the given
     * number of pages and a quiz holding the given number of questions.
     */
    protected function subjectWithChapters(int $chapters = 1, int $pages = 2, int $questions = 2): Subject
    {
        $subject = Subject::factory()->create();

        foreach (range(1, $chapters) as $ignored) {
            $this->addChapter($subject, $pages, $questions);
        }

        return $subject->refresh();
    }

    protected function addChapter(Subject $subject, int $pages = 2, int $questions = 2): Chapter
    {
        /** @var Chapter $chapter */
        $chapter = Chapter::factory()->withQuiz()->create(['subject_id' => $subject->getKey()]);

        ChapterPage::factory()->count($pages)->create(['chapter_id' => $chapter->getKey()]);

        Question::factory()
            ->count($questions)
            ->withOptions()
            ->create(['quiz_id' => $chapter->quiz->getKey()]);

        return $chapter->refresh();
    }

    /**
     * Record chapter completion directly, for tests about what completion
     * unlocks rather than about how it is reached.
     */
    protected function completeChapter(User $student, Chapter $chapter): void
    {
        StudentChapterProgress::query()->create([
            'user_id' => $student->getKey(),
            'chapter_id' => $chapter->getKey(),
            'completed_at' => now(),
        ]);
    }

    protected function correctOptionId(Question $question): int
    {
        return $question->options()->where('is_correct', true)->value('id');
    }

    protected function wrongOptionId(Question $question): int
    {
        return $question->options()->where('is_correct', false)->value('id');
    }

    /**
     * Start (or resume) a chapter's quiz and return the attempt payload.
     *
     * @return array<string, mixed>
     */
    protected function startQuiz(Chapter $chapter): array
    {
        return $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->json('data');
    }

    /**
     * Answer one attempt question. Addressed by the attempt row's id, and the
     * option by its snapshot key, which is what the API hands the client.
     */
    protected function answer(int $attemptId, int $attemptQuestionId, string $optionKey): TestResponse
    {
        return $this->postJson(
            $this->studyUrl."/quiz-attempts/{$attemptId}/questions/{$attemptQuestionId}/answer",
            ['option_key' => $optionKey],
        );
    }

    /**
     * Answer every question of an attempt, choosing its snapshot's correct
     * option each time. Returns the last response.
     */
    protected function answerAllCorrectly(int $attemptId): TestResponse
    {
        $response = null;

        foreach (QuizAttempt::query()->findOrFail($attemptId)->questions as $attemptQuestion) {
            $response = $this->answer(
                $attemptId,
                $attemptQuestion->id,
                (string) $attemptQuestion->correctOptionKey(),
            )->assertOk();
        }

        return $response;
    }
}
