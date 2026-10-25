<?php

namespace Tests\Feature\Study;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\ChapterPage;
use App\Models\Subject;
use App\Models\User;
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
            ->assertJsonPath('data.pages_count', null)
            ->assertJsonPath('data.pages', []);

        $this->assertNotNull(
            $this->getJson($this->studyUrl."/books/{$book->id}")->json('data.pdf_url'),
        );
    }

    public function test_book_details_and_index_return_only_ordered_page_metadata(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);

        $last = BookPage::factory()->create(['book_id' => $book->id, 'order' => 30]);
        $first = BookPage::factory()->create(['book_id' => $book->id, 'order' => 10]);
        $second = BookPage::factory()->create(['book_id' => $book->id, 'order' => 10]);
        $deleted = BookPage::factory()->create(['book_id' => $book->id, 'order' => 5]);
        $deleted->delete();
        BookPage::factory()->create();

        Sanctum::actingAs($this->student());

        $expected = collect([$first, $second, $last])->map(fn (BookPage $page, int $index): array => [
            'id' => $page->id,
            'book_id' => $book->id,
            'position' => $index + 1,
        ])->all();

        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.pages', $expected)
            ->assertJsonPath('data.pages_count', 3)
            ->assertJsonPath('data.title', $book->title)
            ->assertJsonPath('data.author', $book->author)
            ->assertJsonPath('data.type', 'content')
            ->assertJsonPath('data.has_pdf', false);

        $this->getJson($this->studyUrl."/books/{$book->id}/pages")
            ->assertExactJson([
                'success' => true,
                'message' => 'Success.',
                'data' => $expected,
            ]);
    }

    public function test_a_book_page_is_retrieved_by_id_with_exactly_one_body(): void
    {
        $book = Book::factory()->content()->create();
        BookPage::factory()->create(['book_id' => $book->id, 'order' => 10]);
        $page = BookPage::factory()->create([
            'book_id' => $book->id,
            'order' => 30,
            'content' => '<p>Read <strong>one</strong> page.</p>',
        ]);
        BookPage::factory()->create(['book_id' => $book->id, 'order' => 40]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/book-pages/{$page->id}?page=99&per_page=50")
            ->assertExactJson([
                'success' => true,
                'message' => 'Success.',
                'data' => [
                    'id' => $page->id,
                    'book_id' => $book->id,
                    'position' => 2,
                    'content' => '<p>Read <strong>one</strong> page.</p>',
                ],
            ]);
    }

    public function test_book_page_ordering_matches_chapters_after_reordering(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1);
        $chapter = $subject->chapters()->first();
        $chapter->pages()->delete();
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $bookPages = [];
        $chapterPages = [];

        foreach ([30, 10, 10] as $order) {
            $bookPages[] = BookPage::factory()->create(['book_id' => $book->id, 'order' => $order]);
            $chapterPages[] = ChapterPage::factory()->create(['chapter_id' => $chapter->id, 'order' => $order]);
        }

        $bookPages[0]->update(['order' => 5]);
        $chapterPages[0]->update(['order' => 5]);

        Sanctum::actingAs($this->student());

        $bookIndex = $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertOk()->json('data.pages');
        $chapterIndex = $this->getJson($this->studyUrl."/chapters/{$chapter->id}")
            ->assertOk()->json('data.pages');

        $this->assertSame(array_column($chapterIndex, 'position'), array_column($bookIndex, 'position'));
        $this->assertSame(array_map(fn (BookPage $page): int => $page->id, $bookPages), array_column($bookIndex, 'id'));
        $this->assertSame(array_map(fn (ChapterPage $page): int => $page->id, $chapterPages), array_column($chapterIndex, 'id'));

        foreach ($bookIndex as $entry) {
            $this->getJson($this->studyUrl."/book-pages/{$entry['id']}")
                ->assertOk()
                ->assertJsonPath('data.position', $entry['position']);
        }
    }

    public function test_the_page_index_ignores_pagination_and_never_returns_bodies(): void
    {
        $book = Book::factory()->content()->create();
        BookPage::factory()->count(52)->create(['book_id' => $book->id]);

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studyUrl."/books/{$book->id}/pages?per_page=2&page=2")
            ->assertOk()
            ->assertJsonCount(52, 'data')
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.51.position', 52)
            ->assertJsonMissingPath('meta')
            ->assertJsonMissingPath('links');

        foreach ($response->json('data') as $entry) {
            $this->assertSame(['id', 'book_id', 'position'], array_keys($entry));
        }
    }

    public function test_soft_deleted_pages_cannot_be_read_and_do_not_occupy_positions(): void
    {
        $book = Book::factory()->content()->create();
        $deleted = BookPage::factory()->create(['book_id' => $book->id, 'order' => 1]);
        $remaining = BookPage::factory()->create(['book_id' => $book->id, 'order' => 2]);
        $deleted->delete();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/book-pages/{$deleted->id}")->assertNotFound();
        $this->getJson($this->studyUrl."/book-pages/{$remaining->id}")
            ->assertOk()
            ->assertJsonPath('data.position', 1);
    }

    public function test_pages_of_soft_deleted_books_or_subjects_cannot_be_read(): void
    {
        $deletedBook = Book::factory()->content()->create();
        $bookPage = BookPage::factory()->create(['book_id' => $deletedBook->id]);
        $deletedBook->delete();

        $book = Book::factory()->content()->create();
        $subjectPage = BookPage::factory()->create(['book_id' => $book->id]);
        $book->subject->delete();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/book-pages/{$bookPage->id}")
            ->assertNotFound()->assertJsonPath('code', 'BOOK_UNAVAILABLE');
        $this->getJson($this->studyUrl."/book-pages/{$subjectPage->id}")
            ->assertNotFound()->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    public function test_empty_content_books_have_empty_indexes(): void
    {
        $book = Book::factory()->content()->create();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertOk()->assertJsonPath('data.pages', [])->assertJsonPath('data.pages_count', 0);
        $this->getJson($this->studyUrl."/books/{$book->id}/pages")
            ->assertOk()->assertJsonPath('data', []);
        $this->getJson($this->studyUrl.'/book-pages/999999')->assertNotFound();
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

    public function test_a_pdf_book_cannot_expose_page_rows_through_the_individual_endpoint(): void
    {
        $book = Book::factory()->pdf()->create(['total_pages' => 240]);
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/book-pages/{$page->id}")
            ->assertNotFound()->assertJsonPath('code', 'BOOK_CONTENT_UNAVAILABLE');
        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertOk()->assertJsonPath('data.pages', [])->assertJsonPath('data.pages_count', 240);
    }

    public function test_an_unpublished_book_cannot_be_opened(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $draft = Book::factory()->draft()->content()->create(['subject_id' => $subject->id]);
        $archived = Book::factory()->archived()->content()->create(['subject_id' => $subject->id]);

        Sanctum::actingAs($this->student());

        foreach ([$draft, $archived] as $book) {
            $page = BookPage::factory()->create(['book_id' => $book->id]);

            $this->getJson($this->studyUrl."/books/{$book->id}")
                ->assertNotFound()
                ->assertJsonPath('code', 'BOOK_UNAVAILABLE');

            $this->getJson($this->studyUrl."/books/{$book->id}/pages")
                ->assertNotFound()
                ->assertJsonPath('code', 'BOOK_UNAVAILABLE');

            $this->getJson($this->studyUrl."/book-pages/{$page->id}")
                ->assertNotFound()
                ->assertJsonPath('code', 'BOOK_UNAVAILABLE');
        }
    }

    public function test_a_published_book_of_an_unpublished_subject_cannot_be_opened(): void
    {
        $subject = Subject::factory()->draft()->create();
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');

        $this->getJson($this->studyUrl."/book-pages/{$page->id}")
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
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/books/{$book->id}")->assertOk();
        $this->getJson($this->studyUrl."/books/{$book->id}/pages")->assertOk();
        $this->getJson($this->studyUrl."/book-pages/{$page->id}")->assertOk();
    }

    public function test_book_endpoints_require_authentication(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/books")->assertUnauthorized();
        $this->getJson($this->studyUrl."/books/{$book->id}")->assertUnauthorized();
        $this->getJson($this->studyUrl."/books/{$book->id}/pages")->assertUnauthorized();
        $this->getJson($this->studyUrl."/book-pages/{$page->id}")->assertUnauthorized();
    }

    public function test_individual_book_pages_require_a_subscription(): void
    {
        $page = BookPage::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->studyUrl."/book-pages/{$page->id}")
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }
}
