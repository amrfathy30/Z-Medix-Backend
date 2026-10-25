<?php

namespace Tests\Feature\Study;

use App\Enums\StudyProgressStatus;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\QuizAttempt;
use App\Models\StudentChapterProgress;
use App\Models\StudentNote;
use App\Models\StudySession;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/**
 * What the Subject screen's four tabs read off the subject details endpoint:
 * the chapter track, the books, the chapter quizzes and the stats panel.
 *
 * All four come from the one request. Nothing here is stored, and a figure with
 * no recorded activity behind it is null rather than a zero that would read as
 * a result.
 */
class SubjectTabsTest extends StudyTestCase
{
    public function test_subject_details_carry_the_content_totals(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        Book::factory()->count(2)->create(['subject_id' => $subject->id]);
        Book::factory()->draft()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.total_chapters', 2)
            // The draft book is not student-facing content, so it is not counted.
            ->assertJsonPath('data.total_books', 2);
    }

    public function test_a_chapter_row_reports_its_four_state_status(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3, pages: 2);
        [$first, $second] = $subject->chapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.chapters.0.status', StudyProgressStatus::Available->value)
            ->assertJsonPath('data.chapters.0.progress_percentage', 0)
            ->assertJsonPath('data.chapters.1.status', StudyProgressStatus::Locked->value);

        $this->postJson($this->studyUrl."/chapter-pages/{$first->pages()->first()->id}/complete")->assertOk();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.chapters.0.status', StudyProgressStatus::InProgress->value)
            ->assertJsonPath('data.chapters.0.progress_percentage', 50);

        $this->completeChapter($student, $first);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.chapters.0.status', StudyProgressStatus::Completed->value)
            ->assertJsonPath('data.chapters.1.status', StudyProgressStatus::Available->value);

        // Entering the quiz is the other way into a chapter.
        $this->startQuiz($second);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.chapters.1.status', StudyProgressStatus::InProgress->value);
    }

    public function test_a_book_row_reports_its_classification_cover_and_pages(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $book = Book::factory()->content()->create([
            'subject_id' => $subject->id,
            'classification' => 'Clinical Medicine',
        ]);
        BookPage::factory()->count(3)->create(['book_id' => $book->id]);

        $pdf = Book::factory()->pdf()->create([
            'subject_id' => $subject->id,
            'total_pages' => 999,
            'pdf_pages_count' => 240,
        ]);
        $pdf->addMedia(UploadedFile::fake()->image('cover.jpg'))
            ->toMediaCollection(Book::COVER_COLLECTION);

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.books.0.classification', 'Clinical Medicine')
            // A content book is counted from its own page rows.
            ->assertJsonPath('data.books.0.pages_count', 3)
            // No cover uploaded, so none is reported.
            ->assertJsonPath('data.books.0.cover_image', null)
            // Nothing records how far through a book a student has read, so no
            // progress is reported for either kind of book.
            ->assertJsonPath('data.books.0.progress_percentage', null)
            // A PDF book reports its stored extracted count.
            ->assertJsonPath('data.books.1.pages_count', 240);

        $this->assertNotNull($response->json('data.books.1.cover_image'));

        // The same two fields on the single-book endpoint.
        $this->getJson($this->studyUrl."/books/{$pdf->id}")
            ->assertOk()
            ->assertJsonPath('data.pages_count', 240)
            ->assertJsonPath('data.cover_image', $pdf->coverUrl());
    }

    public function test_a_quiz_row_reports_its_status_progress_and_latest_score(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1, questions: 2);
        [$first] = $subject->chapters()->get()->all();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.quizzes.0.status', StudyProgressStatus::Available->value)
            ->assertJsonPath('data.quizzes.0.progress_percentage', 0)
            ->assertJsonPath('data.quizzes.0.latest_score', null)
            ->assertJsonPath('data.quizzes.1.status', StudyProgressStatus::Locked->value);

        $attempt = $this->startQuiz($first);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.quizzes.0.status', StudyProgressStatus::InProgress->value);

        $this->answerAllCorrectly($attempt['id']);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.quizzes.0.status', StudyProgressStatus::Completed->value)
            ->assertJsonPath('data.quizzes.0.progress_percentage', 100)
            ->assertJsonPath('data.quizzes.0.latest_score', 100);
    }

    public function test_the_stats_tab_reports_the_week_the_split_and_the_topics(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1, questions: 2);
        [$first, $second] = $subject->chapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        StudySession::factory()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'duration_seconds' => 3600,
        ]);

        // Finishing the chapter's quiz is what completes the chapter.
        $this->answerAllCorrectly($this->startQuiz($first)['id']);

        $response = $this->getJson($this->studyUrl."/subjects/{$subject->id}")->assertOk();

        // Monday to Sunday, every day present, quiet days as zeros.
        $this->assertCount(7, $response->json('data.stats.weekly_study_time'));
        $this->assertSame('Mon', $response->json('data.stats.weekly_study_time.0.day'));

        $response
            ->assertJsonPath('data.stats.summary.completed_chapters.completed', 1)
            ->assertJsonPath('data.stats.summary.completed_chapters.total', 2)
            ->assertJsonPath('data.stats.summary.average_quiz_score.percentage', 100)
            // No previous month to compare against, so no comparison is drawn.
            ->assertJsonPath('data.stats.summary.total_study_time.change_percentage', null)
            ->assertJsonPath('data.stats.summary.day_streak.days', 1)
            ->assertJsonPath('data.stats.quiz_performance.total_answers', 2)
            ->assertJsonPath('data.stats.quiz_performance.correct_answers', 2)
            ->assertJsonPath('data.stats.quiz_performance.incorrect_answers', 0)
            ->assertJsonPath('data.stats.quiz_performance.correct_percentage', 100)
            ->assertJsonPath('data.stats.quiz_performance.incorrect_percentage', 0)
            // Topics are the subject's chapters, scored on confirmed answers.
            ->assertJsonPath('data.stats.topic_performance.0.chapter_id', $first->id)
            ->assertJsonPath('data.stats.topic_performance.0.chapter_name', $first->title)
            ->assertJsonPath('data.stats.topic_performance.0.performance_percentage', 100)
            // Nothing answered in the second chapter: null, not zero.
            ->assertJsonPath('data.stats.topic_performance.1.chapter_id', $second->id)
            ->assertJsonPath('data.stats.topic_performance.1.performance_percentage', null);

        $activity = collect($response->json('data.stats.recent_activity'));

        $this->assertTrue($activity->contains(
            fn (array $row): bool => $row['type'] === 'chapter_completed' && $row['entity_id'] === $first->id,
        ));
        $this->assertTrue($activity->contains(
            fn (array $row): bool => $row['type'] === 'quiz_completed' && (float) $row['score'] === 100.0,
        ));
    }

    /**
     * The regression behind "Call to a member function getKey() on array": a
     * student with no completed chapters still has their quizzes and notes
     * reported, because the empty first source no longer decides how the three
     * are merged.
     */
    public function test_recent_activity_carries_every_source_when_no_chapter_is_complete(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        $attempt = QuizAttempt::factory()->completed()->create([
            'user_id' => $student->id,
            'quiz_id' => $chapter->quiz->id,
            'completed_at' => now()->subHour(),
        ]);

        $note = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $chapter->pages()->first()->id,
            'created_at' => now()->subMinutes(10),
        ]);

        $activity = collect(
            $this->getJson($this->studyUrl."/subjects/{$subject->id}")
                ->assertOk()
                ->json('data.stats.recent_activity'),
        );

        $this->assertCount(2, $activity);
        $this->assertSame(
            [['note_created', $note->id], ['quiz_completed', $attempt->id]],
            $activity->map(fn (array $row): array => [$row['type'], $row['entity_id']])->all(),
        );
        // The row shape the client reads is unchanged.
        $this->assertSame(
            ['type', 'title', 'timestamp', 'entity_id', 'score'],
            array_keys($activity->first()),
        );
    }

    public function test_recent_activity_is_empty_when_the_student_has_done_nothing(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.stats.recent_activity');
    }

    public function test_recent_activity_orders_all_three_sources_newest_first_without_repeating_one(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        [$first, $second] = $subject->chapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        $attempt = QuizAttempt::factory()->completed()->create([
            'user_id' => $student->id,
            'quiz_id' => $second->quiz->id,
            'completed_at' => now()->subHours(3),
        ]);

        StudentChapterProgress::query()->create([
            'user_id' => $student->id,
            'chapter_id' => $first->id,
            'completed_at' => now()->subHours(2),
        ]);

        $note = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $second->pages()->first()->id,
            'created_at' => now()->subHour(),
        ]);

        $activity = collect(
            $this->getJson($this->studyUrl."/subjects/{$subject->id}")
                ->assertOk()
                ->json('data.stats.recent_activity'),
        );

        $this->assertSame(
            [
                ['note_created', $note->id],
                ['chapter_completed', $first->id],
                ['quiz_completed', $attempt->id],
            ],
            $activity->map(fn (array $row): array => [$row['type'], $row['entity_id']])->all(),
        );
        $this->assertCount(3, $activity->unique(fn (array $row): string => $row['type'].$row['entity_id']));
    }

    public function test_the_statistics_endpoint_is_unchanged(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/statistics")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'subject_id',
                'overall_result',
                'chapters',
                'reading_progress',
                'study_time_seconds',
                'daily_study_time',
                'day_streak',
                'quiz_performance',
            ]]);
    }
}
