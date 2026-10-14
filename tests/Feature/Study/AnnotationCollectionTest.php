<?php

namespace Tests\Feature\Study;

use App\Enums\ContentStatus;
use App\Models\StudentBookmark;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use Laravel\Sanctum\Sanctum;

/**
 * The student's dedicated Highlights, Notes and Bookmarks area: what they
 * marked up across every subject, rather than on one page.
 *
 * Three rules are tested for each of the three kinds, because the three
 * listings are one implementation: only the student's own records are listed,
 * only records on content they can still reach, and each row says which
 * chapter and subject it came from — a passage on its own gives the student no
 * way back.
 */
class AnnotationCollectionTest extends StudyTestCase
{
    public function test_highlights_are_listed_across_subjects_with_their_context(): void
    {
        $firstSubject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $secondSubject = $this->subjectWithChapters(chapters: 1, pages: 1);

        $firstChapter = $firstSubject->chapters()->first();
        $secondChapter = $secondSubject->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create([
            'chapter_page_id' => $firstChapter->pages()->first()->id,
            'selected_text' => 'From the first subject.',
        ]);
        StudentHighlight::factory()->for($student)->create([
            'chapter_page_id' => $secondChapter->pages()->first()->id,
            'selected_text' => 'From the second subject.',
        ]);

        $response = $this->getJson($this->studyUrl.'/highlights')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $contexts = collect($response->json('data'))->pluck('context');

        $this->assertEqualsCanonicalizing(
            [$firstSubject->id, $secondSubject->id],
            $contexts->pluck('subject.id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$firstChapter->id, $secondChapter->id],
            $contexts->pluck('chapter.id')->all(),
        );
        $this->assertSame([1, 1], $contexts->pluck('page.position')->all());
    }

    public function test_notes_and_bookmarks_have_their_own_collections(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentNote::factory()->for($student)->create([
            'chapter_page_id' => $page->id,
            'content' => 'Revise this before the exam.',
        ]);
        StudentBookmark::factory()->for($student)->create([
            'chapter_page_id' => $page->id,
            'selected_text' => 'Come back here.',
        ]);

        $this->getJson($this->studyUrl.'/notes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Revise this before the exam.')
            ->assertJsonPath('data.0.context.page.id', $page->id);

        $this->getJson($this->studyUrl.'/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.selected_text', 'Come back here.')
            ->assertJsonPath('data.0.context.page.id', $page->id);
    }

    public function test_another_students_annotations_are_never_listed(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();
        $other = $this->student();

        StudentHighlight::factory()->for($other)->create(['chapter_page_id' => $page->id]);
        StudentNote::factory()->for($other)->create(['chapter_page_id' => $page->id]);
        StudentBookmark::factory()->for($other)->create(['chapter_page_id' => $page->id]);

        Sanctum::actingAs($this->student());

        foreach (['highlights', 'notes', 'bookmarks'] as $collection) {
            $this->getJson($this->studyUrl.'/'.$collection)
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    /**
     * The collection applies the same access rule as the per-page listing: an
     * unpublished chapter takes its annotations out of the collection too, so
     * the student is never shown a passage from content they can no longer
     * open.
     */
    public function test_annotations_on_unpublished_content_drop_out_of_the_collection(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first();
        $page = $chapter->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $this->getJson($this->studyUrl.'/highlights')->assertOk()->assertJsonCount(1, 'data');

        $chapter->update(['status' => ContentStatus::Draft]);

        $this->getJson($this->studyUrl.'/highlights')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_annotations_on_an_unpublished_subject_drop_out_of_the_collection(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $page = $subject->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $subject->update(['status' => ContentStatus::Draft]);

        $this->getJson($this->studyUrl.'/highlights')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * A chapter the student has not unlocked is not listed either. They cannot
     * have annotated it legitimately, and the rule is the same one the page
     * endpoints apply.
     */
    public function test_annotations_on_a_locked_chapter_are_not_listed(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1);
        $lockedChapter = $subject->chapters()->get()->last();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create([
            'chapter_page_id' => $lockedChapter->pages()->first()->id,
        ]);

        $this->getJson($this->studyUrl.'/highlights')->assertOk()->assertJsonCount(0, 'data');

        // Completing the first chapter unlocks the second, and the annotation
        // becomes listable without anything being rewritten.
        $this->completeChapter($student, $subject->chapters()->first());

        $this->getJson($this->studyUrl.'/highlights')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_collection_can_be_filtered_by_subject_and_chapter(): void
    {
        $firstSubject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $secondSubject = $this->subjectWithChapters(chapters: 1, pages: 1);

        $firstChapter = $firstSubject->chapters()->first();
        $secondChapter = $secondSubject->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create([
            'chapter_page_id' => $firstChapter->pages()->first()->id,
            'selected_text' => 'First.',
        ]);
        StudentHighlight::factory()->for($student)->create([
            'chapter_page_id' => $secondChapter->pages()->first()->id,
            'selected_text' => 'Second.',
        ]);

        $this->getJson($this->studyUrl.'/highlights?subject_id='.$firstSubject->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.selected_text', 'First.');

        $this->getJson($this->studyUrl.'/highlights?chapter_id='.$secondChapter->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.selected_text', 'Second.');
    }

    /**
     * A subject the student cannot reach yields an empty page rather than an
     * error: whether content they cannot see exists is not something the API
     * confirms.
     */
    public function test_filtering_by_an_inaccessible_subject_returns_nothing(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $page = $subject->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $subject->update(['status' => ContentStatus::Draft]);

        $this->getJson($this->studyUrl.'/highlights?subject_id='.$subject->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_collection_is_paginated_newest_first(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        foreach (range(1, 5) as $index) {
            StudentHighlight::factory()->for($student)->create([
                'chapter_page_id' => $page->id,
                'selected_text' => 'Passage '.$index,
                'created_at' => now()->addMinutes($index),
            ]);
        }

        $this->getJson($this->studyUrl.'/highlights?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.selected_text', 'Passage 5')
            ->assertJsonPath('data.1.selected_text', 'Passage 4')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * An out-of-range page size is served at the ceiling rather than refused,
     * matching every other paginated endpoint in the application.
     */
    public function test_per_page_is_capped(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $this->getJson($this->studyUrl.'/highlights?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonCount(1, 'data');
    }

    /**
     * The per-page listing is unchanged by the collection: it still carries no
     * context block, because the client already knows the page it asked about.
     */
    public function test_the_per_page_listing_payload_is_unchanged(): void
    {
        $page = $this->subjectWithChapters(chapters: 1, pages: 1)->chapters()->first()->pages()->first();

        Sanctum::actingAs($student = $this->student());

        StudentHighlight::factory()->for($student)->create(['chapter_page_id' => $page->id]);

        $this->getJson($this->studyUrl."/chapter-pages/{$page->id}/highlights")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.context');
    }
}
