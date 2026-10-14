<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\StudentBookmark;
use Laravel\Sanctum\Sanctum;

/**
 * Bookmarks: the third thing a student marks a page up with, alongside
 * highlights and notes, and governed by the same two rules — the passage is
 * what anchors the record, and a student only ever reaches their own.
 *
 * A bookmark is never edited: it marks a place, and the only changes are
 * creating and removing one.
 */
class StudentBookmarkTest extends StudyTestCase
{
    public function test_a_student_bookmarks_text_on_an_accessible_page(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks", [
            'selected_text' => 'The aortic valve guards the outflow of the left ventricle.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.chapter_page_id', $page->id)
            ->assertJsonPath('data.selected_text', 'The aortic valve guards the outflow of the left ventricle.');

        $this->assertDatabaseHas('student_bookmarks', [
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
        ]);
    }

    public function test_the_selected_text_is_stored_verbatim(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $selectedText = "Preload  — the ventricle's filling.\nAfterload is <em>resistance</em>.";

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks", [
            'selected_text' => $selectedText,
        ])->assertCreated();

        $this->assertSame($selectedText, StudentBookmark::query()->sole()->selected_text);
    }

    public function test_selected_text_is_required(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selected_text');

        $this->assertDatabaseCount('student_bookmarks', 0);
    }

    public function test_a_student_lists_only_their_own_bookmarks_on_a_page(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $other = $this->student();

        StudentBookmark::factory()->for($other)->create([
            'chapter_page_id' => $page->id,
            'selected_text' => 'Another student selected this.',
        ]);

        Sanctum::actingAs($student = $this->student());

        StudentBookmark::factory()->for($student)->create([
            'chapter_page_id' => $page->id,
            'selected_text' => 'Mine.',
        ]);

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.selected_text', 'Mine.');
    }

    public function test_a_student_deletes_their_own_bookmark(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $bookmark = StudentBookmark::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $this->deleteJson($this->studyUrl."/bookmarks/{$bookmark->id}")->assertOk();

        $this->assertDatabaseMissing('student_bookmarks', ['id' => $bookmark->id]);
    }

    /**
     * Another student's bookmark is reported as not found rather than
     * forbidden: a student is never told that a record they cannot reach
     * exists.
     */
    public function test_another_students_bookmark_cannot_be_deleted(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $other = $this->student();
        $bookmark = StudentBookmark::factory()->for($other)->create(['chapter_page_id' => $page->id]);

        Sanctum::actingAs($this->student());

        $this->deleteJson($this->studyUrl."/bookmarks/{$bookmark->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertDatabaseHas('student_bookmarks', ['id' => $bookmark->id]);
    }

    public function test_a_locked_chapter_cannot_be_bookmarked(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        $secondPage = $subject->chapters()->get()->last()->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$secondPage->id}/bookmarks", [
            'selected_text' => 'Locked content.',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->assertDatabaseCount('student_bookmarks', 0);
    }

    /**
     * A chapter that has since been unpublished takes its bookmarks with it,
     * the same way it takes its highlights and notes.
     */
    public function test_an_unpublished_chapter_hides_its_bookmarks(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $bookmark = StudentBookmark::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $chapter->update(['status' => ContentStatus::Draft]);

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks")
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');

        $this->deleteJson($this->studyUrl."/bookmarks/{$bookmark->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');
    }

    public function test_bookmarks_require_authentication(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks")->assertUnauthorized();
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/bookmarks", [
            'selected_text' => 'Anonymous.',
        ])->assertUnauthorized();
        $this->getJson($this->studyUrl.'/bookmarks')->assertUnauthorized();
    }
}
