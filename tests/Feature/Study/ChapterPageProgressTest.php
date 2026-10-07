<?php

namespace Tests\Feature\Study;

use App\Models\ChapterPage;
use App\Models\StudentChapterPageProgress;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class ChapterPageProgressTest extends StudyTestCase
{
    public function test_a_student_marks_a_chapter_page_as_completed(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($student = $this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.page.id', $page->id)
            ->assertJsonPath('data.page.is_completed', true)
            ->assertJsonPath('data.reading_progress.completed_pages', 1)
            ->assertJsonPath('data.reading_progress.total_pages', 2)
            ->assertJsonPath('data.reading_progress.percentage', 50);

        $this->assertDatabaseHas('student_chapter_page_progress', [
            'user_id' => $student->id,
            'chapter_page_id' => $page->id,
        ]);
    }

    /**
     * Reading progress is counted from the completion rows, so 15 of 20 pages
     * read is 75%.
     */
    public function test_reading_progress_is_counted_from_completed_pages(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 20)->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        foreach ($chapter->pages()->take(15)->get() as $page) {
            $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        }

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.reading_progress.completed_pages', 15)
            ->assertJsonPath('data.reading_progress.total_pages', 20)
            ->assertJsonPath('data.reading_progress.percentage', 75);
    }

    /**
     * No percentage is stored, so adding a page re-weighs the chapter without
     * any progress record being rewritten.
     */
    public function test_adding_a_page_reweighs_reading_progress(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        foreach ($chapter->pages as $page) {
            $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        }

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertJsonPath('data.reading_progress.percentage', 100);

        ChapterPage::factory()->create(['chapter_id' => $chapter->id]);

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertJsonPath('data.reading_progress.total_pages', 3)
            ->assertJsonPath('data.reading_progress.completed_pages', 2)
            ->assertJsonPath('data.reading_progress.percentage', 66.67);
    }

    public function test_marking_the_same_page_twice_keeps_the_first_completion(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        Carbon::setTestNow('2026-10-07 11:00:00');
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        Carbon::setTestNow();

        $this->assertSame(1, StudentChapterPageProgress::query()->count());
        $this->assertSame(
            '2026-10-07 09:00:00',
            StudentChapterPageProgress::query()->first()->completed_at->toDateTimeString(),
        );
    }

    public function test_a_page_in_a_locked_chapter_cannot_be_completed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2);
        [, $second] = $subject->publishedChapters()->get()->all();
        $page = $second->pages()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');

        $this->assertDatabaseCount('student_chapter_page_progress', 0);
    }

    public function test_one_students_page_progress_does_not_leak_to_another(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 2)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($this->student());
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();

        Sanctum::actingAs($this->student());
        $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.reading_progress.completed_pages', 0);
    }

    /**
     * Pages are read, chapters are completed by their quiz: finishing every
     * page does not complete the chapter or unlock the next one.
     */
    public function test_completing_every_page_does_not_complete_the_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2);
        [$first, $second] = $subject->publishedChapters()->get()->all();

        Sanctum::actingAs($this->student());

        foreach ($first->pages as $page) {
            $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        }

        $this->getJson($this->studyUrl."/chapters/{$first->id}")
            ->assertJsonPath('data.is_completed', false);

        $this->getJson($this->studyUrl."/chapters/{$second->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');
    }
}
