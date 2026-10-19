<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\ReportCase;
use App\Models\StudentChapterPageProgress;
use App\Models\StudentChapterProgress;
use App\Models\StudentStudyPosition;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fellowship\StudentFellowshipTestCase;

/**
 * The student's home screen.
 *
 * Extends the student Fellowship case for its session helpers — generating and
 * answering a session through the API is the only way to produce the session
 * rows the dashboard reads — and adds the study fixtures beside them, since the
 * dashboard is the one screen that spans both modules.
 */
class StudentDashboardTest extends StudentFellowshipTestCase
{
    private string $dashboardUrl = '/api/student/dashboard';

    private string $studyUrl = '/api/student/study';

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->getJson($this->dashboardUrl)->assertUnauthorized();
    }

    public function test_the_dashboard_returns_all_five_sections(): void
    {
        $this->actingAsStudent();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'continue_learning',
                    'fellowships',
                    'subjects',
                    'report_cases',
                    'recent_activity',
                ],
            ]);
    }

    /**
     * A student with no history at all gets five empty lists rather than nulls
     * or missing keys.
     */
    public function test_empty_sections_are_returned_as_empty_arrays(): void
    {
        $this->actingAsStudent();

        $response = $this->getJson($this->dashboardUrl)->assertOk();

        foreach (['continue_learning', 'fellowships', 'subjects', 'report_cases', 'recent_activity'] as $section) {
            $this->assertSame([], $response->json("data.{$section}"), "`{$section}` is not an empty array.");
        }
    }

    // ─── Continue Learning ──────────────────────────────────────────────────

    public function test_continue_learning_carries_a_started_subject_with_its_resume_position(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2);
        $chapter = $subject->publishedChapters()->first();
        $page = $chapter->pages()->first();

        $this->actingAsStudent();

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data.continue_learning')
            ->assertJsonPath('data.continue_learning.0.type', 'subject')
            ->assertJsonPath('data.continue_learning.0.id', $subject->id)
            ->assertJsonPath('data.continue_learning.0.title', $subject->name)
            ->assertJsonPath('data.continue_learning.0.current_chapter.id', $chapter->id)
            ->assertJsonPath('data.continue_learning.0.progress.total_chapters', 2)
            // The resume block the frontend continues through, identical to
            // what `study/subjects/{subject}/resume` returns.
            ->assertJsonPath('data.continue_learning.0.resume.subject_id', $subject->id)
            ->assertJsonPath('data.continue_learning.0.resume.has_position', true)
            ->assertJsonPath('data.continue_learning.0.resume.chapter.id', $chapter->id)
            ->assertJsonPath('data.continue_learning.0.resume.chapter_page.id', $page->id)
            ->assertJsonPath('data.continue_learning.0.session', null);
    }

    /**
     * The resume block agrees with the dedicated resume endpoint, which is what
     * makes it usable through the existing APIs.
     */
    public function test_the_subject_resume_block_matches_the_resume_endpoint(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, questions: 2);
        $chapter = $subject->publishedChapters()->first();

        $this->actingAsStudent();

        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();

        $resume = $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")->assertOk()->json('data');

        $this->assertSame(
            $resume,
            $this->getJson($this->dashboardUrl)->assertOk()->json('data.continue_learning.0.resume'),
        );
    }

    public function test_continue_learning_carries_an_open_fellowship_session_with_its_statistics(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        $this->actingAsStudent();

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerCurrent($session)->assertOk();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data.continue_learning')
            ->assertJsonPath('data.continue_learning.0.type', 'fellowship')
            ->assertJsonPath('data.continue_learning.0.id', $fellowship->id)
            ->assertJsonPath('data.continue_learning.0.title', $fellowship->name)
            ->assertJsonPath('data.continue_learning.0.progress.answered_questions', 1)
            ->assertJsonPath('data.continue_learning.0.progress.total_questions', 2)
            ->assertJsonPath('data.continue_learning.0.progress.percentage', 50)
            ->assertJsonPath('data.continue_learning.0.resume.session_id', $session->id)
            ->assertJsonPath('data.continue_learning.0.resume.can_resume', true)
            ->assertJsonPath('data.continue_learning.0.session.id', $session->id)
            ->assertJsonPath('data.continue_learning.0.session.answered_questions', 1)
            ->assertJsonPath('data.continue_learning.0.current_chapter', null);
    }

    /**
     * Only work the student actually began is offered. A published subject they
     * have never opened holds no position, and a finished session is not
     * something to continue.
     */
    public function test_continue_learning_leaves_out_untouched_subjects_and_finished_sessions(): void
    {
        $this->subjectWithChapters();

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $this->actingAsStudent();

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);

        $this->assertTrue($session->refresh()->isCompleted());

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data.continue_learning')
            // The subject is still on offer in its own section; it is only
            // "continue learning" that requires having started.
            ->assertJsonCount(1, 'data.subjects');
    }

    public function test_continue_learning_is_ordered_by_latest_activity(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $subject = $this->subjectWithChapters(pages: 2);
        $page = $subject->publishedChapters()->first()->pages()->first();

        $this->actingAsStudent();

        $this->travelTo(now()->subHours(2));
        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $this->travelTo(now()->addHours(2));
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        $this->travelBack();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(2, 'data.continue_learning')
            ->assertJsonPath('data.continue_learning.0.type', 'subject')
            ->assertJsonPath('data.continue_learning.1.type', 'fellowship')
            ->assertJsonPath('data.continue_learning.1.id', $fellowship->id)
            ->assertJsonPath('data.continue_learning.1.resume.session_id', $session->id);
    }

    // ─── Visibility ─────────────────────────────────────────────────────────

    public function test_only_published_subjects_active_fellowships_and_active_report_cases_are_listed(): void
    {
        $published = Subject::factory()->create(['name' => 'Anatomy']);
        Subject::factory()->draft()->create(['name' => 'Draft subject']);
        Subject::factory()->archived()->create(['name' => 'Archived subject']);

        $active = $this->fellowship('Emergency Medicine');
        $this->question($active);
        Fellowship::factory()->inactive()->create(['name' => 'Retired bank']);

        $visibleCase = ReportCase::factory()->create(['title' => 'Acute Appendicitis']);
        ReportCase::factory()->inactive()->create(['title' => 'Withdrawn case']);

        $this->actingAsStudent();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.id', $published->id)
            ->assertJsonCount(1, 'data.fellowships')
            ->assertJsonPath('data.fellowships.0.id', $active->id)
            ->assertJsonCount(1, 'data.report_cases')
            ->assertJsonPath('data.report_cases.0.id', $visibleCase->id);
    }

    /**
     * A session on a Fellowship that has since been deactivated is not offered
     * either, because the Fellowship itself is no longer reachable.
     */
    public function test_a_session_on_a_deactivated_fellowship_is_not_offered(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $this->actingAsStudent();

        $this->generateSession($fellowship, ['question_count' => 1]);

        $fellowship->update(['is_active' => false]);

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data.continue_learning')
            ->assertJsonCount(0, 'data.fellowships');
    }

    // ─── Section contents ───────────────────────────────────────────────────

    public function test_a_fellowship_card_carries_the_banks_question_topic_and_difficulty_totals(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Cardiology', difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, topic: 'Cardiology', difficulty: QuizDifficulty::Hard);
        $this->question($fellowship, topic: 'Neurology', difficulty: QuizDifficulty::Hard);

        $this->actingAsStudent();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonPath('data.fellowships.0.id', $fellowship->id)
            ->assertJsonPath('data.fellowships.0.name', $fellowship->name)
            ->assertJsonPath('data.fellowships.0.total_questions', 3)
            ->assertJsonPath('data.fellowships.0.total_topics', 2)
            // Easy and Hard are in the bank; the platform's other levels are
            // not, so they are not counted.
            ->assertJsonPath('data.fellowships.0.total_difficulty_levels', 2);
    }

    public function test_a_subject_card_carries_progress_counts_and_the_current_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3);
        $chapters = $subject->publishedChapters()->get();

        $student = $this->actingAsStudent();

        $this->completeChapter($student, $chapters[0]);

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonPath('data.subjects.0.id', $subject->id)
            ->assertJsonPath('data.subjects.0.chapters_count', 3)
            ->assertJsonPath('data.subjects.0.books_count', 0)
            ->assertJsonPath('data.subjects.0.chapters.completed_chapters', 1)
            ->assertJsonPath('data.subjects.0.chapters.total_chapters', 3)
            ->assertJsonPath('data.subjects.0.chapters.percentage', 33.33)
            ->assertJsonPath('data.subjects.0.current_chapter.id', $chapters[1]->id);
    }

    public function test_a_report_case_carries_its_write_up_and_pdf_url(): void
    {
        $case = ReportCase::factory()->create();

        $this->actingAsStudent();

        $this->getJson($this->dashboardUrl)
            ->assertOk()
            ->assertJsonPath('data.report_cases.0.id', $case->id)
            ->assertJsonPath('data.report_cases.0.title', $case->title)
            ->assertJsonPath('data.report_cases.0.description', $case->description)
            ->assertJsonPath('data.report_cases.0.source', $case->source)
            // No PDF uploaded, so the url is null rather than absent.
            ->assertJsonPath('data.report_cases.0.pdf_url', null);
    }

    // ─── Limits ─────────────────────────────────────────────────────────────

    public function test_the_card_sections_carry_three_items_and_recent_activity_five(): void
    {
        foreach (range(1, 4) as $index) {
            ReportCase::factory()->create();
            $fellowship = $this->fellowship("Bank {$index}");
            $this->question($fellowship);
        }

        $subjects = collect(range(1, 4))->map(fn (): Subject => $this->subjectWithChapters(chapters: 1, pages: 1));

        $student = $this->actingAsStudent();

        // Four started subjects and eight activities, so every section has
        // more to offer than it may show.
        foreach ($subjects as $subject) {
            $chapter = $subject->publishedChapters()->first();

            $this->postJson($this->studyUrl."/chapter-pages/{$chapter->pages()->first()->id}/complete")->assertOk();
            $this->completeChapter($student, $chapter);
            $this->endedStudySession($student, $subject);
        }

        $response = $this->getJson($this->dashboardUrl)->assertOk();

        $response->assertJsonCount(3, 'data.fellowships')
            ->assertJsonCount(3, 'data.subjects')
            ->assertJsonCount(3, 'data.report_cases')
            ->assertJsonCount(3, 'data.continue_learning')
            ->assertJsonCount(5, 'data.recent_activity');
    }

    // ─── Recent Activity ────────────────────────────────────────────────────

    public function test_recent_activity_reports_persisted_activity_newest_first(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);
        $chapter = $subject->publishedChapters()->first();

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $student = $this->actingAsStudent();

        $this->travelTo(now()->subHours(3));
        $this->endedStudySession($student, $subject);

        $this->travelTo(now()->addHour());
        $attempt = $this->startQuiz($chapter);
        $this->answerAllCorrectly($attempt['id']);

        $this->travelTo(now()->addHour());
        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);
        $this->travelBack();

        $activity = $this->getJson($this->dashboardUrl)->assertOk()->json('data.recent_activity');

        $this->assertCount(4, $activity);

        $this->assertSame(
            // Finishing the quiz completes the chapter in the same instant, so
            // both are recorded; the tie is broken on the activity type.
            ['fellowship_session_completed', 'quiz_completed', 'chapter_completed', 'study_session_ended'],
            array_column($activity, 'type'),
        );

        $this->assertSame($session->id, $activity[0]['related_id']);
        $this->assertSame('fellowship_session', $activity[0]['related_type']);
        $this->assertSame($chapter->id, $activity[2]['related_id']);
        $this->assertSame('chapter', $activity[2]['related_type']);
        $this->assertSame($subject->id, $activity[3]['related_id']);
        $this->assertNotNull($activity[0]['occurred_at']);
    }

    public function test_recent_activity_leaves_out_activity_on_unpublished_content(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);

        $student = $this->actingAsStudent();

        $this->completeChapter($student, $subject->publishedChapters()->first());
        $this->endedStudySession($student, $subject);

        $this->getJson($this->dashboardUrl)->assertOk()->assertJsonCount(2, 'data.recent_activity');

        $subject->update(['status' => ContentStatus::Archived]);

        $this->getJson($this->dashboardUrl)->assertOk()->assertJsonCount(0, 'data.recent_activity');
    }

    // ─── Isolation ──────────────────────────────────────────────────────────

    public function test_another_students_progress_sessions_and_activity_never_appear(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        $chapter = $subject->publishedChapters()->first();

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $other = $this->student();

        $this->actingAsStudent($other);
        $otherSession = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->postJson($this->studyUrl."/chapter-pages/{$chapter->pages()->first()->id}/complete")->assertOk();
        $this->endedStudySession($other, $subject);

        $mine = $this->actingAsStudent();

        $response = $this->getJson($this->dashboardUrl)->assertOk();

        $response->assertJsonCount(0, 'data.continue_learning')
            ->assertJsonCount(0, 'data.recent_activity')
            // The other student's completion must not move my own progress.
            ->assertJsonPath('data.subjects.0.chapters.completed_chapters', 0)
            ->assertJsonPath('data.subjects.0.current_chapter.id', $chapter->id);

        $this->assertNotSame($other->id, $mine->id);
        $this->assertSame($other->id, FellowshipQuizSession::query()->findOrFail($otherSession->id)->user_id);
    }

    // ─── Read-only ──────────────────────────────────────────────────────────

    public function test_reading_the_dashboard_changes_nothing(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        ReportCase::factory()->create();

        $student = $this->actingAsStudent();

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->postJson($this->studyUrl."/chapter-pages/{$subject->publishedChapters()->first()->pages()->first()->id}/complete")->assertOk();
        $this->endedStudySession($student, $subject);

        $before = $this->mutableState($session);

        $this->getJson($this->dashboardUrl)->assertOk();
        $this->getJson($this->dashboardUrl)->assertOk();

        $this->assertSame($before, $this->mutableState($session));
    }

    /**
     * The row counts and session state a GET must leave untouched, including
     * the position's own `updated_at` — reading where the student left off must
     * not look like fresh activity.
     *
     * @return array<string, mixed>
     */
    private function mutableState(FellowshipQuizSession $session): array
    {
        $fresh = $session->fresh();

        return [
            'sessions' => FellowshipQuizSession::query()->count(),
            'session_status' => $fresh->status?->value,
            'session_position' => $fresh->current_position,
            'session_updated_at' => $fresh->updated_at?->toIso8601String(),
            'attempts' => QuizAttempt::query()->count(),
            'study_sessions' => StudySession::query()->count(),
            'positions' => StudentStudyPosition::query()->count(),
            'position_updated_at' => (string) StudentStudyPosition::query()->value('updated_at'),
            'page_progress' => StudentChapterPageProgress::query()->count(),
            'chapter_progress' => StudentChapterProgress::query()->count(),
        ];
    }

    // ─── Study fixtures ─────────────────────────────────────────────────────

    /**
     * A published subject whose chapters are published, each with the given
     * number of pages and a quiz holding the given number of questions — the
     * shape the admin module guarantees for published study content.
     */
    private function subjectWithChapters(int $chapters = 1, int $pages = 2, int $questions = 2): Subject
    {
        $subject = Subject::factory()->create();

        foreach (range(1, $chapters) as $ignored) {
            /** @var Chapter $chapter */
            $chapter = Chapter::factory()->withQuiz()->create(['subject_id' => $subject->getKey()]);

            ChapterPage::factory()->count($pages)->create(['chapter_id' => $chapter->getKey()]);

            Question::factory()
                ->count($questions)
                ->withOptions()
                ->create(['quiz_id' => $chapter->quiz->getKey()]);
        }

        return $subject->refresh();
    }

    /**
     * Record chapter completion directly, for tests about what completion
     * reports rather than about how it is reached.
     */
    private function completeChapter(User $student, Chapter $chapter): void
    {
        StudentChapterProgress::query()->create([
            'user_id' => $student->getKey(),
            'chapter_id' => $chapter->getKey(),
            'completed_at' => now(),
        ]);
    }

    /**
     * A study session the student opened and closed, which is the only kind
     * Recent Activity reports.
     */
    private function endedStudySession(User $student, Subject $subject): void
    {
        StudySession::query()->create([
            'user_id' => $student->getKey(),
            'subject_id' => $subject->getKey(),
            'started_at' => now()->subMinutes(30),
            'ended_at' => now(),
            'duration_seconds' => 1800,
        ]);
    }

    /**
     * Start (or resume) a chapter's quiz and return the attempt payload.
     *
     * @return array<string, mixed>
     */
    private function startQuiz(Chapter $chapter): array
    {
        return $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->json('data');
    }

    /**
     * Answer every question of an attempt, choosing its snapshot's correct
     * option each time.
     */
    private function answerAllCorrectly(int $attemptId): TestResponse
    {
        $response = null;

        foreach (QuizAttempt::query()->findOrFail($attemptId)->questions as $attemptQuestion) {
            $response = $this->postJson(
                $this->studyUrl."/quiz-attempts/{$attemptId}/questions/{$attemptQuestion->id}/answer",
                ['option_key' => (string) $attemptQuestion->correctOptionKey()],
            )->assertOk();
        }

        return $response;
    }
}
