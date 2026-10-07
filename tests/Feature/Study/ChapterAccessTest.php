<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\Subject;
use Laravel\Sanctum\Sanctum;

class ChapterAccessTest extends StudyTestCase
{
    public function test_the_first_chapter_of_a_published_subject_is_accessible(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        $first = $subject->publishedChapters()->first();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.reading_progress.total_pages', 2)
            ->assertJsonPath('data.reading_progress.completed_pages', 0)
            ->assertJsonPath('data.reading_progress.percentage', 0)
            ->assertJsonCount(2, 'data.pages')
            ->assertJsonPath('data.quiz.question_count', 2);
    }

    public function test_a_later_chapter_is_locked_until_the_previous_one_is_completed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [$first, $second] = $subject->publishedChapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/chapters/{$second->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->completeChapter($student, $first);

        $this->getJson($this->studyUrl."/chapters/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id);
    }

    public function test_a_pages_access_follows_its_chapters(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [$first, $second] = $subject->publishedChapters()->get()->all();
        $lockedPage = $second->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/chapter-pages/{$lockedPage->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->completeChapter($student, $first);

        $this->getJson($this->studyUrl."/chapter-pages/{$lockedPage->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lockedPage->id)
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonStructure(['data' => ['id', 'chapter_id', 'position', 'is_completed', 'content']]);
    }

    public function test_a_quiz_cannot_be_entered_in_a_locked_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapters/{$second->id}/quiz/start")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_an_unpublished_chapter_is_not_study_content(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $chapter = $subject->chapters()->first();
        $chapter->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');
    }

    public function test_a_chapter_of_an_unpublished_subject_is_not_study_content(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $chapter = $subject->chapters()->first();
        $subject->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    /**
     * An unpublished chapter is not part of the sequence, so it can neither be
     * completed nor leave the chapters after it permanently locked.
     */
    public function test_an_unpublished_chapter_does_not_block_the_sequence(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3);
        [$first, $middle, $last] = $subject->chapters()->get()->all();
        $middle->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/chapters/{$last->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->completeChapter($student, $first);

        $this->getJson($this->studyUrl."/chapters/{$last->id}")->assertOk();
    }

    public function test_a_soft_deleted_chapter_cannot_be_reached(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $chapter = $subject->chapters()->first();
        $chapter->delete();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")->assertNotFound();
    }

    public function test_the_chapter_endpoints_reject_a_guest(): void
    {
        $chapter = $this->subjectWithChapters()->chapters()->first();

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")->assertUnauthorized();
    }

    public function test_a_subject_with_no_published_chapters_resolves_to_no_chapter(): void
    {
        $subject = Subject::factory()->create();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.has_position', false)
            ->assertJsonPath('data.chapter', null);
    }
}
