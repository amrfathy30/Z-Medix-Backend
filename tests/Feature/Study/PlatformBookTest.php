<?php

namespace Tests\Feature\Study;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Subject;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Reading a subject's platform books.
 *
 * Books are the subject's other content type: they belong to the subject
 * directly, are independent of its chapters and are not sequenced behind them,
 * so a book is readable without having completed anything. Only published books
 * of a published subject are ever reported.
 *
 * A PDF book is opened as a file and a content book as pages, and asking for
 * the wrong one is refused rather than answered with an empty list.
 */
class PlatformBookTest extends StudyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Platform book PDFs live on the admin's public media disk; faking it
        // keeps the uploads these tests create out of the real storage tree.
        Storage::fake('filament_public');
    }

    public function test_a_student_lists_a_subjects_published_books(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        $first = Book::factory()->content()->create(['subject_id' => $subject->id, 'order' => 1]);
        $second = Book::factory()->pdf()->create(['subject_id' => $subject->id, 'order' => 2]);
        $draft = Book::factory()->draft()->create(['subject_id' => $subject->id]);
        $archived = Book::factory()->archived()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studyUrl."/subjects/{$subject->id}/books")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            // The admin's own ordering is kept.
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($draft->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_a_books_list_row_describes_which_reader_to_open(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        $content = Book::factory()->content()->create(['subject_id' => $subject->id, 'order' => 1]);
        BookPage::factory()->count(3)->create(['book_id' => $content->id]);

        Book::factory()->withPdfFile()->create(['subject_id' => $subject->id, 'order' => 2]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/books")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'content')
            ->assertJsonPath('data.0.pages_count', 3)
            ->assertJsonPath('data.0.has_pdf', false)
            ->assertJsonPath('data.1.type', 'pdf')
            ->assertJsonPath('data.1.has_pdf', true)
            // A PDF book keeps its material in its file, not in page rows.
            ->assertJsonPath('data.1.pages_count', null);
    }

    public function test_a_pdf_book_hands_over_its_file(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->withPdfFile('anatomy.pdf')->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.has_pdf', true)
            ->assertJsonPath('data.pdf_file_name', 'anatomy.pdf')
            ->assertJsonPath('data.pages_count', null);

        $this->assertNotNull(
            $this->getJson($this->studyUrl."/books/{$book->id}")->json('data.pdf_url'),
        );
    }

    public function test_a_content_book_serves_its_pages_in_order(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);

        foreach (range(1, 3) as $index) {
            BookPage::factory()->create([
                'book_id' => $book->id,
                'order' => $index,
                'content' => "Page {$index} body",
            ]);
        }

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}/pages")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.0.content', 'Page 1 body')
            ->assertJsonPath('data.2.position', 3)
            ->assertJsonPath('data.2.content', 'Page 3 body');
    }

    /**
     * Position follows the listing, so page 3 is page 3 on the second page of
     * results too — not page 1 again.
     */
    public function test_page_positions_continue_across_pagination(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);

        foreach (range(1, 5) as $index) {
            BookPage::factory()->create(['book_id' => $book->id, 'order' => $index]);
        }

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}/pages?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.position', 3)
            ->assertJsonPath('data.1.position', 4)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_a_pdf_book_has_no_pages_to_list(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->pdf()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}/pages")
            ->assertNotFound()
            ->assertJsonPath('code', 'BOOK_CONTENT_UNAVAILABLE');
    }

    public function test_an_unpublished_book_cannot_be_opened(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $draft = Book::factory()->draft()->content()->create(['subject_id' => $subject->id]);
        $archived = Book::factory()->archived()->content()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        foreach ([$draft, $archived] as $book) {
            $this->getJson($this->studyUrl."/books/{$book->id}")
                ->assertNotFound()
                ->assertJsonPath('code', 'BOOK_UNAVAILABLE');

            $this->getJson($this->studyUrl."/books/{$book->id}/pages")
                ->assertNotFound()
                ->assertJsonPath('code', 'BOOK_UNAVAILABLE');
        }
    }

    public function test_a_published_book_of_an_unpublished_subject_cannot_be_opened(): void
    {
        $subject = Subject::factory()->draft()->create();
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/books")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    /**
     * Books sit beside the chapter track, not behind it: an unfinished first
     * chapter locks the second chapter, never a book.
     */
    public function test_books_are_readable_without_completing_any_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 3);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);
        BookPage::factory()->create(['book_id' => $book->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")->assertOk();
        $this->getJson($this->studyUrl."/books/{$book->id}/pages")->assertOk();
    }

    public function test_book_endpoints_require_authentication(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/books")->assertUnauthorized();
        $this->getJson($this->studyUrl."/books/{$book->id}")->assertUnauthorized();
        $this->getJson($this->studyUrl."/books/{$book->id}/pages")->assertUnauthorized();
    }
}
