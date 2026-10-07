<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\StudentNote;
use Laravel\Sanctum\Sanctum;

class StudentNoteTest extends StudyTestCase
{
    public function test_a_student_writes_a_note_on_selected_text(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/notes", [
            'selected_text' => 'The mitral valve lies between the left atrium and the left ventricle.',
            'content' => 'Also called the bicuspid valve. Comes up in every exam.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.chapter_page_id', $page->id)
            ->assertJsonPath('data.selected_text', 'The mitral valve lies between the left atrium and the left ventricle.')
            ->assertJsonPath('data.content', 'Also called the bicuspid valve. Comes up in every exam.');

        $this->assertDatabaseHas('student_notes', [
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'selected_text' => 'The mitral valve lies between the left atrium and the left ventricle.',
            'content' => 'Also called the bicuspid valve. Comes up in every exam.',
        ]);
    }

    public function test_a_note_requires_both_the_selected_text_and_the_content(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/notes", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selected_text', 'content']);

        $this->assertDatabaseCount('student_notes', 0);
    }

    public function test_a_student_updates_their_own_note_content(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $note = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'selected_text' => 'Systole is the contraction phase.',
            'content' => 'First pass.',
        ]);

        $this->patchJson($this->studyUrl."/notes/{$note->id}", ['content' => 'Rewritten after the quiz.'])
            ->assertOk()
            ->assertJsonPath('data.id', $note->id)
            ->assertJsonPath('data.content', 'Rewritten after the quiz.');

        $this->assertDatabaseHas('student_notes', [
            'id' => $note->id,
            'content' => 'Rewritten after the quiz.',
        ]);
    }

    /**
     * The passage is what ties the note back to the page, so an update never
     * rewrites it even when the client sends one.
     */
    public function test_updating_a_note_leaves_the_selected_text_untouched(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $note = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'selected_text' => 'Systole is the contraction phase.',
        ]);

        $this->patchJson($this->studyUrl."/notes/{$note->id}", [
            'content' => 'Updated.',
            'selected_text' => 'Something else entirely.',
        ])
            ->assertOk()
            ->assertJsonPath('data.selected_text', 'Systole is the contraction phase.');

        $this->assertSame('Systole is the contraction phase.', $note->refresh()->selected_text);
    }

    public function test_note_content_is_required_on_update(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $note = StudentNote::factory()->create(['user_id' => $student->id, 'chapter_page_id' => $page->id]);

        $this->patchJson($this->studyUrl."/notes/{$note->id}", ['content' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_a_student_deletes_their_own_note(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $note = StudentNote::factory()->create(['user_id' => $student->id, 'chapter_page_id' => $page->id]);

        $this->deleteJson($this->studyUrl."/notes/{$note->id}")->assertOk();

        $this->assertDatabaseMissing('student_notes', ['id' => $note->id]);
    }

    public function test_a_student_lists_only_their_own_notes_on_a_page(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        StudentNote::factory()->create([
            'user_id' => $this->student()->id,
            'chapter_page_id' => $page->id,
            'content' => 'Another student wrote this.',
        ]);

        Sanctum::actingAs($student = $this->student());

        $mine = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'content' => 'I wrote this.',
        ]);

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.content', 'I wrote this.');
    }

    public function test_notes_from_another_page_are_not_listed(): void
    {
        [$first, $second] = $this->subjectWithChapters(chapters: 1, pages: 2)
            ->chapters()->first()->pages->all();

        Sanctum::actingAs($student = $this->student());

        StudentNote::factory()->create(['user_id' => $student->id, 'chapter_page_id' => $second->id]);

        $this->getJson($this->studyUrl."/chapter-pages/{$first->id}/notes")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Another student's note is reported as not found rather than forbidden,
     * whether they try to read it, edit it or remove it.
     */
    public function test_another_students_note_cannot_be_updated_or_deleted(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        $note = StudentNote::factory()->create([
            'user_id' => $this->student()->id,
            'chapter_page_id' => $page->id,
            'content' => 'Not yours.',
        ]);

        Sanctum::actingAs($this->student());

        $this->patchJson($this->studyUrl."/notes/{$note->id}", ['content' => 'Hijacked.'])
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->deleteJson($this->studyUrl."/notes/{$note->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertDatabaseHas('student_notes', [
            'id' => $note->id,
            'content' => 'Not yours.',
        ]);
    }

    public function test_a_page_in_a_locked_chapter_cannot_receive_a_note(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();
        $page = $second->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/notes", [
            'selected_text' => 'Reaching ahead.',
            'content' => 'Should not stick.',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->assertDatabaseCount('student_notes', 0);
    }

    public function test_notes_in_a_locked_chapter_cannot_be_listed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();
        $page = $second->pages()->first();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/notes")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');
    }

    public function test_a_page_in_an_unpublished_chapter_cannot_receive_a_note(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();
        $page = $chapter->pages()->first();
        $chapter->update(['status' => ContentStatus::Draft]);

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/notes", [
            'selected_text' => 'Not published yet.',
            'content' => 'Should not stick.',
        ])
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');

        $this->assertDatabaseCount('student_notes', 0);
    }

    /**
     * A chapter that is unpublished after the fact takes its notes with it: the
     * same access rules decide reading the page and editing an annotation on
     * it.
     */
    public function test_a_note_in_a_chapter_that_becomes_unavailable_cannot_be_updated(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $note = StudentNote::factory()->create([
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
            'content' => 'Written while published.',
        ]);

        $chapter->update(['status' => ContentStatus::Draft]);

        $this->patchJson($this->studyUrl."/notes/{$note->id}", ['content' => 'Edited after the fact.'])
            ->assertNotFound()
            ->assertJsonPath('code', 'CHAPTER_UNAVAILABLE');

        $this->assertDatabaseHas('student_notes', [
            'id' => $note->id,
            'content' => 'Written while published.',
        ]);
    }

    /**
     * An annotation is readable on its own later: the passage and the student's
     * writing sit on the row, and the chapter and subject are reached through
     * the page rather than copied onto it.
     */
    public function test_a_note_resolves_its_chapter_and_subject_through_the_page(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $chapter = $subject->chapters()->first();
        $page = $chapter->pages()->first();

        $note = StudentNote::factory()->create([
            'user_id' => $this->student()->id,
            'chapter_page_id' => $page->id,
        ]);

        $note = StudentNote::query()->with('chapterPage.chapter.subject')->findOrFail($note->id);

        $this->assertSame($page->id, $note->chapterPage->id);
        $this->assertSame($chapter->id, $note->chapterPage->chapter->id);
        $this->assertSame($subject->id, $note->chapterPage->chapter->subject->id);
        $this->assertNotEmpty($note->selected_text);
        $this->assertNotEmpty($note->content);
    }

    public function test_writing_a_note_requires_authentication(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/notes", [
            'selected_text' => 'Anonymous.',
            'content' => 'Anonymous.',
        ])->assertUnauthorized();
    }
}
