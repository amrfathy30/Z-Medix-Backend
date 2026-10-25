<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Enums\QuizAttemptStatus;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Subject;
use Laravel\Sanctum\Sanctum;

/**
 * How a student finds something to study: the subjects they may open, and what
 * one subject holds.
 *
 * A subject holds two independent kinds of content, so its details carry a
 * chapter track and a book list side by side — never books nested inside
 * chapters (business context §4).
 *
 * The chapter track reports `is_accessible` from the backend's own rule, so the
 * client draws a locked chapter rather than deciding for itself what is locked.
 */
class SubjectDiscoveryTest extends StudyTestCase
{
    public function test_a_student_lists_published_subjects_only(): void
    {
        $published = $this->subjectWithChapters(chapters: 1);
        $draft = Subject::factory()->draft()->create();
        $archived = Subject::factory()->archived()->create();

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studyUrl.'/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $published->id);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($draft->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_the_listing_counts_content_and_progress(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3, pages: 2);

        Book::factory()->count(2)->create(['subject_id' => $subject->id]);
        Book::factory()->draft()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($student = $this->student());

        $this->completeChapter($student, $subject->chapters()->first());

        $this->getJson($this->studyUrl.'/subjects')
            ->assertOk()
            ->assertJsonPath('data.0.chapters_count', 3)
            // The draft book is not counted: it is not student-facing content.
            ->assertJsonPath('data.0.books_count', 2)
            ->assertJsonPath('data.0.chapters.completed_chapters', 1)
            ->assertJsonPath('data.0.chapters.total_chapters', 3)
            ->assertJsonPath('data.0.chapters.percentage', 33.33);
    }

    public function test_the_listing_can_be_searched_by_subject_name(): void
    {
        Subject::factory()->create(['name' => 'Cardiology']);
        $anatomy = Subject::factory()->create(['name' => 'Gross Anatomy']);
        Subject::factory()->create(['name' => 'Anatomy of the Heart']);
        Subject::factory()->draft()->create(['name' => 'Anatomy Draft']);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl.'/subjects?search=anatomy')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Anatomy of the Heart')
            ->assertJsonPath('data.1.id', $anatomy->id);

        $this->getJson($this->studyUrl.'/subjects?search=nothing-matches')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // No search term is no filter.
        $this->getJson($this->studyUrl.'/subjects?search=')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_search_term_is_kept_in_the_pagination_links(): void
    {
        Subject::factory()->count(3)->sequence(
            ['name' => 'Anatomy A'],
            ['name' => 'Anatomy B'],
            ['name' => 'Anatomy C'],
        )->create();

        Sanctum::actingAs($this->student());

        $next = $this->getJson($this->studyUrl.'/subjects?search=anatomy&per_page=2')
            ->assertOk()
            ->json('links.next');

        $this->assertStringContainsString('search=anatomy', $next);
    }

    public function test_an_overlong_search_term_is_rejected(): void
    {
        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl.'/subjects?search='.str_repeat('a', 101))
            ->assertUnprocessable();
    }

    public function test_each_listed_subject_names_the_chapter_the_student_is_on(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3, pages: 1);
        $subject->update(['name' => 'Anatomy']);
        [$first, $second, $third] = $subject->chapters()->get()->all();
        $empty = Subject::factory()->create(['name' => 'Zzz Empty']);

        Sanctum::actingAs($student = $this->student());

        // Nothing completed: the first chapter.
        $this->getJson($this->studyUrl.'/subjects')
            ->assertOk()
            ->assertJsonPath('data.0.current_chapter.id', $first->id)
            ->assertJsonPath('data.0.current_chapter.title', $first->title)
            ->assertJsonPath('data.0.current_chapter.order', $first->order)
            // No published chapters, so nowhere to be.
            ->assertJsonPath('data.1.id', $empty->id)
            ->assertJsonPath('data.1.current_chapter', null);

        $this->completeChapter($student, $first);

        $this->getJson($this->studyUrl.'/subjects')
            ->assertJsonPath('data.0.current_chapter.id', $second->id);

        $this->completeChapter($student, $second);
        $this->completeChapter($student, $third);

        // The whole track done: it stays on the last chapter.
        $this->getJson($this->studyUrl.'/subjects')
            ->assertJsonPath('data.0.current_chapter.id', $third->id);
    }

    public function test_the_current_chapter_skips_drafts_and_is_per_student(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3, pages: 1);
        [$first, $second, $third] = $subject->chapters()->get()->all();
        $second->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($student = $this->student());
        $this->completeChapter($student, $first);

        // The draft is not part of the track, so the next one is the third.
        $this->getJson($this->studyUrl.'/subjects')
            ->assertJsonPath('data.0.current_chapter.id', $third->id);

        // Another student's progress is theirs alone.
        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl.'/subjects')
            ->assertJsonPath('data.0.current_chapter.id', $first->id);
    }

    public function test_subject_details_carry_chapters_and_books_side_by_side(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2);
        $book = Book::factory()->content()->create([
            'subject_id' => $subject->id,
            'title' => 'Clinical Anatomy',
            'author' => 'A. Author',
        ]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $subject->id)
            ->assertJsonCount(2, 'data.chapters')
            ->assertJsonCount(1, 'data.books')
            ->assertJsonPath('data.books.0.id', $book->id)
            ->assertJsonPath('data.books.0.title', 'Clinical Anatomy')
            ->assertJsonPath('data.books.0.author', 'A. Author')
            // Books are not nested inside a chapter, and never will be.
            ->assertJsonMissingPath('data.chapters.0.books');
    }

    public function test_subject_details_carry_the_description(): void
    {
        $subject = Subject::factory()->create(['description' => 'The heart and blood vessels.']);
        $bare = Subject::factory()->create();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'The heart and blood vessels.');

        // Subjects authored before the field existed have none.
        $this->getJson($this->studyUrl."/subjects/{$bare->id}")
            ->assertOk()
            ->assertJsonPath('data.description', null);
    }

    public function test_subject_details_list_the_chapter_quizzes_in_track_order(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1, questions: 3);
        [$first, $second] = $subject->chapters()->get()->all();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.quizzes')
            ->assertJsonPath('data.quizzes.0.id', $first->quiz->id)
            ->assertJsonPath('data.quizzes.0.chapter_id', $first->id)
            ->assertJsonPath('data.quizzes.0.chapter_title', $first->title)
            ->assertJsonPath('data.quizzes.0.title', $first->quiz->title)
            ->assertJsonPath('data.quizzes.0.difficulty', $first->quiz->difficulty->value)
            ->assertJsonPath('data.quizzes.0.question_count', 3)
            ->assertJsonPath('data.quizzes.0.attempts_count', 0)
            ->assertJsonPath('data.quizzes.0.last_attempt', null)
            // A quiz is as reachable as its chapter.
            ->assertJsonPath('data.quizzes.0.is_accessible', true)
            ->assertJsonPath('data.quizzes.1.chapter_id', $second->id)
            ->assertJsonPath('data.quizzes.1.is_accessible', false);
    }

    public function test_a_quiz_row_reports_the_students_latest_attempt(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        $finished = $this->startQuiz($chapter);
        $this->answerAllCorrectly($finished['id']);

        $open = $this->startQuiz($chapter);
        $this->assertNotSame($finished['id'], $open['id']);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.quizzes.0.attempts_count', 2)
            ->assertJsonPath('data.quizzes.0.last_attempt.id', $open['id'])
            ->assertJsonPath('data.quizzes.0.last_attempt.status', QuizAttemptStatus::InProgress->value)
            ->assertJsonPath('data.quizzes.0.last_attempt.percentage', 0);
    }

    public function test_a_quiz_row_scores_the_latest_attempt_like_its_history_row(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 4);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $this->answerAllCorrectly($attempt['id']);

        $percentage = $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")
            ->json('data.0.percentage');

        $this->assertSame(100.0, (float) $percentage);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertJsonPath('data.quizzes.0.last_attempt.status', QuizAttemptStatus::Completed->value)
            ->assertJsonPath('data.quizzes.0.last_attempt.percentage', $percentage);
    }

    public function test_another_students_attempts_do_not_show_on_a_quiz_row(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());
        $this->startQuiz($chapter);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.quizzes.0.attempts_count', 0)
            ->assertJsonPath('data.quizzes.0.last_attempt', null);
    }

    public function test_unpublished_chapters_quizzes_are_not_listed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        $subject->chapters()->get()->last()->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quizzes');
    }

    public function test_the_chapter_track_reports_lock_and_completion_state(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3, pages: 2);
        $chapters = $subject->chapters()->get();

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.chapters.0.is_accessible', true)
            ->assertJsonPath('data.chapters.0.is_completed', false)
            ->assertJsonPath('data.chapters.1.is_accessible', false)
            ->assertJsonPath('data.chapters.2.is_accessible', false);

        $this->completeChapter($student, $chapters->first());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.chapters.0.is_completed', true)
            ->assertJsonPath('data.chapters.1.is_accessible', true)
            // Still one step at a time.
            ->assertJsonPath('data.chapters.2.is_accessible', false);
    }

    public function test_the_chapter_track_reports_references_and_reading_progress(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 4);
        $chapter = $subject->chapters()->first();
        $chapter->update(['references' => "Gray's Anatomy, 42nd ed."]);

        Sanctum::actingAs($this->student());

        $page = $chapter->pages()->first();
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.chapters.0.references', "Gray's Anatomy, 42nd ed.")
            ->assertJsonPath('data.chapters.0.reading_progress.completed_pages', 1)
            ->assertJsonPath('data.chapters.0.reading_progress.total_pages', 4)
            ->assertJsonPath('data.chapters.0.reading_progress.percentage', 25)
            ->assertJsonPath('data.reading_progress.completed_pages', 1)
            ->assertJsonPath('data.reading_progress.total_pages', 4);
    }

    public function test_unpublished_chapters_are_not_part_of_the_track(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        $second = $subject->chapters()->get()->last();
        $second->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.chapters')
            ->assertJsonPath('data.chapters_progress.total_chapters', 1);
    }

    public function test_an_unpublished_subject_cannot_be_opened(): void
    {
        $subject = Subject::factory()->draft()->create();
        Chapter::factory()->withQuiz()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    public function test_subject_discovery_requires_authentication(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        $this->getJson($this->studyUrl.'/subjects')->assertUnauthorized();
        $this->getJson($this->studyUrl."/subjects/{$subject->id}")->assertUnauthorized();
    }
}
