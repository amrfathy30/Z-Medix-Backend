<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\StudentStudyPosition;
use Laravel\Sanctum\Sanctum;

class StudyResumeTest extends StudyTestCase
{
    /**
     * A student who has not studied the subject yet gets the chapter they may
     * start, flagged as not being a remembered position.
     */
    public function test_a_subject_with_no_recorded_position_offers_the_first_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        $first = $subject->publishedChapters()->first();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.subject_id', $subject->id)
            ->assertJsonPath('data.has_position', false)
            ->assertJsonPath('data.chapter.id', $first->id)
            ->assertJsonPath('data.chapter_page', null)
            ->assertJsonPath('data.quiz_attempt', null)
            ->assertJsonPath('data.last_active_at', null);
    }

    public function test_completing_a_page_records_it_as_the_resume_position(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 3);
        $chapter = $subject->chapters()->first();
        $page = $chapter->pages()->get()[1];

        Sanctum::actingAs($student = $this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', true)
            ->assertJsonPath('data.chapter.id', $chapter->id)
            ->assertJsonPath('data.chapter_page.id', $page->id)
            ->assertJsonPath('data.chapter_page.position', 2)
            ->assertJsonPath('data.quiz_attempt', null);

        $this->assertSame(1, StudentStudyPosition::query()->where('user_id', $student->id)->count());
    }

    public function test_entering_a_quiz_records_the_attempt_and_current_question(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, questions: 3);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $start = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', true)
            ->assertJsonPath('data.chapter.id', $chapter->id)
            ->assertJsonPath('data.chapter_page', null)
            ->assertJsonPath('data.quiz_attempt.id', $start->json('data.id'))
            ->assertJsonPath('data.quiz_attempt.current_question.id', $start->json('data.questions.0.id'));
    }

    /**
     * A page position and a quiz position are mutually exclusive: moving from
     * one to the other replaces it rather than leaving both set.
     */
    public function test_a_position_is_either_a_page_or_a_quiz_but_never_both(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 2, questions: 2);
        $chapter = $subject->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();

        $resume = $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.chapter_page', null);

        $this->assertNotNull($resume->json('data.quiz_attempt.id'));

        $position = StudentStudyPosition::query()->firstOrFail();
        $this->assertNull($position->chapter_page_id);
        $this->assertNotNull($position->quiz_attempt_id);
    }

    /**
     * The position is kept apart from study sessions, so ending the session the
     * student was studying in does not lose their place.
     */
    public function test_ending_a_study_session_does_not_lose_the_resume_position(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 2);
        $page = $subject->chapters()->first()->pages()->first();

        Sanctum::actingAs($this->student());

        $sessionId = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->json('data.id');
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        $this->postJson($this->studyUrl."/sessions/{$sessionId}/end")->assertOk();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', true)
            ->assertJsonPath('data.chapter_page.id', $page->id);
    }

    /**
     * A finished attempt is history, not somewhere to resume into.
     */
    public function test_a_completed_attempt_is_not_offered_as_a_resume_point(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $attemptId = $this->startQuiz($chapter)['id'];

        $this->answerAllCorrectly($attemptId);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', true)
            ->assertJsonPath('data.chapter.id', $chapter->id)
            ->assertJsonPath('data.quiz_attempt', null)
            ->assertJsonPath('data.chapter_page', null);
    }

    /**
     * A position pointing at content the student may no longer reach is dropped
     * rather than returned, so the client cannot resume into a refused request.
     */
    public function test_a_position_in_a_chapter_that_was_unpublished_falls_back(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2);
        [$first, $second] = $subject->publishedChapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        $this->completeChapter($student, $first);
        $page = $second->pages()->first();
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        $second->update(['status' => ContentStatus::Draft]);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', false)
            ->assertJsonPath('data.chapter.id', $first->id)
            ->assertJsonPath('data.chapter_page', null);
    }

    public function test_the_resume_endpoint_rejects_an_unpublished_subject_and_a_guest(): void
    {
        $subject = $this->subjectWithChapters();

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")->assertUnauthorized();

        $subject->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    public function test_one_students_position_does_not_leak_to_another(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 2);
        $page = $subject->chapters()->first()->pages()->first();

        Sanctum::actingAs($this->student());
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        Sanctum::actingAs($this->student());
        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', false);
    }
}
