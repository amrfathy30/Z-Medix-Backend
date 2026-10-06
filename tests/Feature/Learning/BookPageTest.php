<?php

namespace Tests\Feature\Learning;

use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\BookResource\RelationManagers\PagesRelationManager;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\ChapterPage;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Livewire;

class BookPageTest extends LearningTestCase
{
    private function pagesTable(Book $book)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(PagesRelationManager::class, [
                'ownerRecord' => $book,
                'pageClass' => ViewBook::class,
            ]);
    }

    public function test_page_belongs_to_a_book(): void
    {
        $book = Book::factory()->content()->create();
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $this->assertTrue($page->book->is($book));
        $this->assertTrue($book->pages->contains($page));
    }

    // ─── A page has no title ─────────────────────────────────────────────────

    public function test_book_pages_have_no_title_column(): void
    {
        $this->assertFalse(DatabaseSchema::hasColumn('book_pages', 'title'));
        $this->assertNotContains('title', (new BookPage)->getFillable());
    }

    public function test_the_page_form_accepts_content_only(): void
    {
        $relationManager = new PagesRelationManager;

        $componentNames = collect($relationManager->form(Schema::make($relationManager))->getFlatComponents(withHidden: true))
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['content'], $componentNames);
    }

    public function test_page_can_be_created_from_the_book_with_content_only(): void
    {
        $book = Book::factory()->content()->create();

        $this->pagesTable($book)
            ->callAction(TestAction::make('create')->table(), [
                'content' => '<p>Book content</p>',
            ])
            ->assertHasNoActionErrors();

        $page = $book->pages()->sole();

        $this->assertSame($book->id, $page->book_id);
        $this->assertSame('<p>Book content</p>', $page->content);
        $this->assertSame(1, $page->order);
    }

    public function test_page_content_can_be_updated(): void
    {
        $book = Book::factory()->content()->create();
        $page = BookPage::factory()->content('<p>Original</p>')->create(['book_id' => $book->id]);

        $this->pagesTable($book)
            ->callAction(TestAction::make('edit')->table($page), [
                'content' => '<p>Updated with <strong>markup</strong></p>',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('<p>Updated with <strong>markup</strong></p>', $page->refresh()->content);
    }

    /**
     * Book pages reuse the chapter-page editor convention, so attachments,
     * accepted types and size limits stay identical between the two.
     */
    public function test_the_page_editor_matches_the_chapter_page_editor(): void
    {
        $bookEditor = collect((new PagesRelationManager)->form(Schema::make(new PagesRelationManager))->getFlatComponents(withHidden: true))
            ->first();

        $chapterManager = new \App\Filament\Resources\ChapterResource\RelationManagers\PagesRelationManager;
        $chapterEditor = collect($chapterManager->form(Schema::make($chapterManager))->getFlatComponents(withHidden: true))
            ->first();

        $this->assertInstanceOf($chapterEditor::class, $bookEditor);
        $this->assertSame(
            $chapterEditor->getFileAttachmentsAcceptedFileTypes(),
            $bookEditor->getFileAttachmentsAcceptedFileTypes(),
        );
        $this->assertSame(
            $chapterEditor->getFileAttachmentsMaxSize(),
            $bookEditor->getFileAttachmentsMaxSize(),
        );
        $this->assertSame(
            $chapterEditor->getFileAttachmentsVisibility(),
            $bookEditor->getFileAttachmentsVisibility(),
        );
    }

    // ─── Pages are identified by their position ──────────────────────────────

    public function test_pages_are_labelled_by_their_position_in_the_book(): void
    {
        $book = Book::factory()->content()->create();

        $first = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $second = BookPage::factory()->order(2)->create(['book_id' => $book->id]);
        $third = BookPage::factory()->order(3)->create(['book_id' => $book->id]);

        $this->assertSame(1, $first->positionInBook());
        $this->assertSame(2, $second->positionInBook());
        $this->assertSame(3, $third->positionInBook());

        $this->assertSame('Page 1', $first->pageLabel());
        $this->assertSame('Page 2', $second->pageLabel());
        $this->assertSame('Page 3', $third->pageLabel());
    }

    public function test_page_numbering_is_scoped_to_the_book(): void
    {
        $book = Book::factory()->content()->create();
        $otherBook = Book::factory()->content()->create();

        $own = BookPage::factory()->create(['book_id' => $book->id]);
        $foreign = BookPage::factory()->create(['book_id' => $otherBook->id]);

        $this->assertSame('Page 1', $own->pageLabel());
        $this->assertSame('Page 1', $foreign->pageLabel());
    }

    public function test_the_pages_table_shows_the_sequence_numbers(): void
    {
        $book = Book::factory()->content()->create();

        $first = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $second = BookPage::factory()->order(2)->create(['book_id' => $book->id]);

        $this->pagesTable($book)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertTableColumnStateSet('page_number', 'Page 1', $first)
            ->assertTableColumnStateSet('page_number', 'Page 2', $second)
            ->assertSee('Page 1')
            ->assertSee('Page 2');
    }

    public function test_a_book_only_shows_its_own_pages(): void
    {
        $book = Book::factory()->content()->create();
        $otherBook = Book::factory()->content()->create();

        $own = BookPage::factory()->create(['book_id' => $book->id]);
        $foreign = BookPage::factory()->create(['book_id' => $otherBook->id]);

        $this->pagesTable($book)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    // ─── Ordering ───────────────────────────────────────────────────────────

    public function test_page_order_is_assigned_sequentially_within_its_book(): void
    {
        $book = Book::factory()->content()->create();
        $otherBook = Book::factory()->content()->create();

        $first = BookPage::factory()->create(['book_id' => $book->id]);
        $second = BookPage::factory()->create(['book_id' => $book->id]);
        $otherFirst = BookPage::factory()->create(['book_id' => $otherBook->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);
        $this->assertSame(1, $otherFirst->order);
    }

    public function test_pages_are_returned_in_order(): void
    {
        $book = Book::factory()->content()->create();

        $third = BookPage::factory()->order(3)->create(['book_id' => $book->id]);
        $first = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $second = BookPage::factory()->order(2)->create(['book_id' => $book->id]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $book->pages()->pluck('id')->all(),
        );
    }

    public function test_the_pages_table_is_reorderable_by_drag_and_drop(): void
    {
        $book = Book::factory()->content()->create();

        $this->assertSame('order', $this->pagesTable($book)->instance()->getTable()->getReorderColumn());
    }

    public function test_dragging_the_last_page_to_the_front_renumbers_every_page(): void
    {
        $book = Book::factory()->content()->create();

        $one = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $two = BookPage::factory()->order(2)->create(['book_id' => $book->id]);
        $three = BookPage::factory()->order(3)->create(['book_id' => $book->id]);

        $this->pagesTable($book)
            ->call('reorderTable', [$three->getKey(), $one->getKey(), $two->getKey()]);

        $this->assertSame(1, $three->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);
        $this->assertSame(3, $two->refresh()->order);

        // Previously "Page 3" is now "Page 1", and the others shift down by one.
        $this->assertSame('Page 1', $three->pageLabel());
        $this->assertSame('Page 2', $one->pageLabel());
        $this->assertSame('Page 3', $two->pageLabel());
    }

    public function test_reordering_one_books_pages_never_touches_another_book(): void
    {
        $book = Book::factory()->content()->create();
        $otherBook = Book::factory()->content()->create();

        $one = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $two = BookPage::factory()->order(2)->create(['book_id' => $book->id]);

        $otherOne = BookPage::factory()->order(1)->create(['book_id' => $otherBook->id]);
        $otherTwo = BookPage::factory()->order(2)->create(['book_id' => $otherBook->id]);

        $this->pagesTable($book)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $two->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);

        $this->assertSame(1, $otherOne->refresh()->order);
        $this->assertSame(2, $otherTwo->refresh()->order);
    }

    public function test_reordering_book_pages_never_touches_chapter_pages(): void
    {
        $book = Book::factory()->content()->create();
        $one = BookPage::factory()->order(1)->create(['book_id' => $book->id]);
        $two = BookPage::factory()->order(2)->create(['book_id' => $book->id]);

        $chapterOne = ChapterPage::factory()->order(1)->create();
        $chapterTwo = ChapterPage::factory()->order(2)->create(['chapter_id' => $chapterOne->chapter_id]);

        $this->pagesTable($book)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $chapterOne->refresh()->order);
        $this->assertSame(2, $chapterTwo->refresh()->order);
    }

    // ─── An empty content book is valid in any status ───────────────────────

    public function test_a_content_book_can_exist_with_zero_pages(): void
    {
        $book = Book::factory()->content()->draft()->create();

        $this->assertSame(0, $book->pagesCount());

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm([
                'title' => 'Still pageless',
                'status' => ContentStatus::Draft->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Still pageless', $book->refresh()->title);
        $this->assertSame(ContentStatus::Draft, $book->status);
    }

    public function test_a_content_book_can_be_published_with_zero_pages(): void
    {
        $book = Book::factory()->content()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
        $this->assertSame(0, $book->pagesCount());
    }

    public function test_a_content_book_can_be_archived_with_zero_pages(): void
    {
        $book = Book::factory()->content()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Archived->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Archived, $book->refresh()->status);
    }

    public function test_pages_can_still_be_added_after_the_book_is_published(): void
    {
        $book = Book::factory()->content()->create(['status' => ContentStatus::Published]);

        $this->pagesTable($book)
            ->callAction(TestAction::make('create')->table(), [
                'content' => '<p>Added after publishing</p>',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $book->pagesCount());
        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
    }

    public function test_a_content_book_can_be_published_once_it_has_a_page(): void
    {
        $book = Book::factory()->content()->draft()->create();
        BookPage::factory()->create(['book_id' => $book->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
        $this->assertSame(1, $book->pagesCount());
    }

    // ─── Book view ──────────────────────────────────────────────────────────

    public function test_the_content_book_view_shows_the_pages_table_and_no_pdf_section(): void
    {
        $book = Book::factory()->content()->create();
        BookPage::factory()->create(['book_id' => $book->id]);

        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertTrue(PagesRelationManager::canViewForRecord($book, ViewBook::class));

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewBook::class, ['record' => $book->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentHidden('book-pdf-section')
            ->assertSeeLivewire(PagesRelationManager::class);
    }

    public function test_page_can_be_soft_deleted_and_restored(): void
    {
        $book = Book::factory()->content()->create();
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $this->pagesTable($book)
            ->callAction(TestAction::make('delete')->table($page));

        $this->assertSoftDeleted('book_pages', ['id' => $page->id]);

        $this->pagesTable($book)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($page));

        $this->assertDatabaseHas('book_pages', ['id' => $page->id, 'deleted_at' => null]);
    }
}
