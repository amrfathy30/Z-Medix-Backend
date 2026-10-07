<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\StudentHighlight;
use Laravel\Sanctum\Sanctum;

class StudentHighlightTest extends StudyTestCase
{
    public function test_a_student_highlights_text_on_an_accessible_page(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [
            'selected_text' => 'The mitral valve lies between the left atrium and the left ventricle.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.chapter_page_id', $page->id)
            ->assertJsonPath('data.selected_text', 'The mitral valve lies between the left atrium and the left ventricle.');

        $this->assertDatabaseHas('student_highlights', [
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
        ]);
    }

    /**
     * The selected passage is what anchors a highlight, so it is persisted as
     * the student selected it rather than re-derived from the page: internal
     * spacing, punctuation, line breaks and non-ASCII characters all survive.
     * Only the surrounding whitespace is dropped, by the API group's own
     * `TrimStrings` middleware.
     */
    public function test_the_selected_text_is_stored_verbatim(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $selectedText = "Systole  — the heart's contraction phase.\nDiastole is <em>relaxation</em>.";

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [
            'selected_text' => $selectedText,
        ])
            ->assertCreated()
            ->assertJsonPath('data.selected_text', $selectedText);

        $this->assertSame($selectedText, StudentHighlight::query()->sole()->selected_text);
    }

    public function test_selected_text_is_required(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selected_text');

        $this->assertDatabaseCount('student_highlights', 0);
    }

    public function test_a_student_lists_only_their_own_highlights_on_a_page(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $other = $this->student();

        StudentHighlight::factory()->create([
            'user_id' => $other->id,
            'chapter_page_id' => $page->id,
            'selected_text' => 'Another student picked this out.',
        ]);

        Sanctum::actingAs($student = $this->student());

        $mine = StudentHighlight::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'selected_text' => 'I picked this out.',
        ]);

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/highlights")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.selected_text', 'I picked this out.');
    }

    /**
     * Highlights are listed per page, so one made elsewhere in the chapter does
     * not show up on this page.
     */
    public function test_highlights_from_another_page_are_not_listed(): void
    {
        [$first, $second] = $this->subjectWithChapters(chapters: 1, pages: 2)
            ->chapters()->first()->pages->all();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->create(['user_id' => $student->id, 'chapter_page_id' => $second->id]);

        $this->getJson($this->studyUrl."/chapter-pages/{$first->id}/highlights")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_student_deletes_their_own_highlight(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $highlight = StudentHighlight::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
        ]);

        $this->deleteJson($this->studyUrl."/highlights/{$highlight->id}")->assertOk();

        $this->assertDatabaseMissing('student_highlights', ['id' => $highlight->id]);
    }

    /**
     * Another student's highlight is reported as not found rather than
     * forbidden: a student is never told it exists.
     */
    public function test_another_students_highlight_cannot_be_deleted(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        $highlight = StudentHighlight::factory()->create([
            'user_id' => $this->student()->id,
            'chapter_page_id' => $page->id,
        ]);

        Sanctum::actingAs($this->student());

        $this->deleteJson($this->studyUrl."/highlights/{$highlight->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertDatabaseHas('student_highlights', ['id' => $highlight->id]);
    }

    public function test_a_page_in_a_locked_chapter_cannot_be_highlighted(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();
        $page = $second->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [
            'selected_text' => 'Reaching ahead.',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->assertDatabaseCount('student_highlights', 0);
    }

    public function test_highlights_in_a_locked_chapter_cannot_be_listed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();
        $page = $second->pages()->first();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/highlights")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');
    }

    /**
     * An unpublished chapter is not study content at all, so its pages are
     * reported as not found rather than locked.
     */
    public function test_a_page_in_an_unpublished_chapter_cannot_be_highlighted(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();
        $page = $chapter->pages()->first();
        $chapter->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [
            'selected_text' => 'Not published yet.',
        ])
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');

        $this->assertDatabaseCount('student_highlights', 0);
    }

    /**
     * A chapter that is unpublished after the fact takes its highlights with
     * it: the same access rules decide reading the page and deleting an
     * annotation on it.
     */
    public function test_a_highlight_in_a_chapter_that_becomes_unavailable_cannot_be_deleted(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $highlight = StudentHighlight::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
        ]);

        $chapter->update(['status' => ContentStatus::Draft]);

        $this->deleteJson($this->studyUrl."/highlights/{$highlight->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');

        $this->assertDatabaseHas('student_highlights', ['id' => $highlight->id]);
    }

    public function test_highlighting_requires_authentication(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/highlights", [
            'selected_text' => 'Anonymous.',
        ])->assertUnauthorized();
    }
}
