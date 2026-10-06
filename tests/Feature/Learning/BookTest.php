<?php

namespace Tests\Feature\Learning;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Chapter;
use App\Models\Subject;
use App\Services\Learning\BookService;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Livewire\Livewire;

class BookTest extends LearningTestCase
{
    private function booksTable(Subject $subject)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(BooksRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createBook(Subject $subject, array $data = [])
    {
        return $this->booksTable($subject)
            ->callAction(TestAction::make('create')->table(), array_replace([
                'title' => 'Clinical Anatomy',
                'author' => 'Dr. Snell',
                'type' => BookType::Content->value,
                'status' => ContentStatus::Draft->value,
            ], $data));
    }

    /** @return array<int, string> */
    private function createFormFieldNames(): array
    {
        $relationManager = new BooksRelationManager;

        return collect($relationManager->form(Schema::make($relationManager))->getFlatComponents(withHidden: true))
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();
    }

    // ─── Relationship ───────────────────────────────────────────────────────

    public function test_book_belongs_to_a_subject(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->create(['subject_id' => $subject->id]);

        $this->assertTrue($book->subject->is($subject));
        $this->assertTrue($subject->books->contains($book));
    }

    public function test_books_are_independent_from_chapters(): void
    {
        $book = Book::factory()->create();

        $this->assertFalse(method_exists($book, 'chapter'));
        $this->assertFalse(method_exists($book, 'chapters'));
    }

    public function test_book_creation_from_the_subject_assigns_subject_id_automatically(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, ['title' => 'Gray\'s Anatomy'])->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Gray\'s Anatomy')->sole();

        $this->assertSame($subject->id, $book->subject_id);
    }

    public function test_the_create_form_never_asks_for_the_subject(): void
    {
        $this->assertNotContains('subject_id', $this->createFormFieldNames());
    }

    // ─── Status is a free administrative choice ─────────────────────────────

    public function test_a_book_can_be_created_as_a_draft(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, [
            'title' => 'Draft book',
            'status' => ContentStatus::Draft->value,
        ])->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Draft book')->sole();

        $this->assertSame(ContentStatus::Draft, $book->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'status' => 'draft']);
    }

    public function test_a_book_can_be_created_as_published(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, [
            'title' => 'Published book',
            'status' => ContentStatus::Published->value,
        ])->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Published book')->sole();

        $this->assertSame(ContentStatus::Published, $book->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'status' => 'published']);
    }

    public function test_a_book_can_be_created_as_archived(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, [
            'title' => 'Archived book',
            'status' => ContentStatus::Archived->value,
        ])->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Archived book')->sole();

        $this->assertSame(ContentStatus::Archived, $book->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'status' => 'archived']);
    }

    public function test_the_create_form_offers_the_status_choice(): void
    {
        $this->assertContains('status', $this->createFormFieldNames());
    }

    public function test_the_create_form_offers_no_manual_order_input(): void
    {
        $this->assertNotContains('order', $this->createFormFieldNames());
    }

    public function test_the_service_persists_a_published_status_as_given(): void
    {
        $subject = Subject::factory()->create();

        $book = app(BookService::class)->create($subject, [
            'title' => 'Published through the service',
            'type' => BookType::Content->value,
            'status' => ContentStatus::Published->value,
        ]);

        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'status' => 'published']);
    }

    public function test_the_service_persists_an_archived_status_as_given(): void
    {
        $subject = Subject::factory()->create();

        $book = app(BookService::class)->create($subject, [
            'title' => 'Archived through the service',
            'type' => BookType::Pdf->value,
            'status' => ContentStatus::Archived->value,
        ]);

        $this->assertSame(ContentStatus::Archived, $book->refresh()->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'status' => 'archived']);
    }

    public function test_a_book_created_without_a_status_falls_back_to_draft(): void
    {
        $subject = Subject::factory()->create();

        $book = app(BookService::class)->create($subject, [
            'title' => 'No status supplied',
            'type' => BookType::Content->value,
        ]);

        $this->assertSame(ContentStatus::Draft, $book->refresh()->status);
    }

    public function test_status_can_be_changed_freely_on_edit(): void
    {
        $book = Book::factory()->content()->draft()->create();

        foreach ([ContentStatus::Published, ContentStatus::Archived, ContentStatus::Draft] as $status) {
            Livewire::actingAs($this->superAdmin(), 'admin')
                ->test(EditBook::class, ['record' => $book->getRouteKey()])
                ->fillForm(['status' => $status->value])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame($status, $book->refresh()->status);
        }
    }

    // ─── Fields and validation ──────────────────────────────────────────────

    public function test_book_title_is_required(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, ['title' => null])
            ->assertHasActionErrors(['title' => 'required']);
    }

    public function test_book_author_is_optional(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, ['title' => 'Anonymous work', 'author' => null])
            ->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Anonymous work')->sole();

        $this->assertNull($book->author);
    }

    public function test_book_type_is_required(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, ['type' => null])
            ->assertHasActionErrors(['type' => 'required']);
    }

    public function test_only_pdf_and_content_are_accepted_as_a_book_type(): void
    {
        $this->assertSame(
            ['pdf', 'content'],
            array_column(BookType::cases(), 'value'),
        );
        $this->assertSame(['pdf', 'content'], array_keys(BookResource::typeOptions()));
    }

    public function test_an_unknown_book_type_is_rejected_by_the_form(): void
    {
        $subject = Subject::factory()->create();

        $this->createBook($subject, ['type' => 'audio'])
            ->assertHasActionErrors(['type']);

        $this->assertSame(0, $subject->books()->count());
    }

    public function test_book_type_and_status_are_stored_as_enums(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        $this->assertSame(BookType::Pdf, $book->refresh()->type);
        $this->assertSame(ContentStatus::Draft, $book->status);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'type' => 'pdf', 'status' => 'draft']);
    }

    public function test_books_have_no_pdf_path_column(): void
    {
        foreach (['pdf_path', 'file_path', 'path', 'image', 'file'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('books', $column),
                "books must not store a raw {$column} column.",
            );
        }
    }

    // ─── Ordering ───────────────────────────────────────────────────────────

    public function test_book_order_is_assigned_sequentially_within_its_subject(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $first = Book::factory()->create(['subject_id' => $subject->id]);
        $second = Book::factory()->create(['subject_id' => $subject->id]);
        $otherFirst = Book::factory()->create(['subject_id' => $otherSubject->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);

        // The same numeric order may exist under a different subject.
        $this->assertSame(1, $otherFirst->order);
    }

    public function test_a_new_book_is_appended_to_the_end_of_its_subject(): void
    {
        $subject = Subject::factory()->create();
        Book::factory()->order(1)->create(['subject_id' => $subject->id]);
        Book::factory()->order(2)->create(['subject_id' => $subject->id]);

        $this->createBook($subject, ['title' => 'Appended book'])->assertHasNoActionErrors();

        $this->assertDatabaseHas('books', [
            'title' => 'Appended book',
            'subject_id' => $subject->id,
            'order' => 3,
        ]);
    }

    public function test_books_are_returned_in_a_deterministic_order(): void
    {
        $subject = Subject::factory()->create();

        $third = Book::factory()->order(3)->create(['subject_id' => $subject->id, 'title' => 'Third']);
        $first = Book::factory()->order(1)->create(['subject_id' => $subject->id, 'title' => 'First']);
        $second = Book::factory()->order(2)->create(['subject_id' => $subject->id, 'title' => 'Second']);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $subject->books()->pluck('title')->all(),
        );
        $this->assertSame([$first->id, $second->id, $third->id], $subject->books()->pluck('id')->all());
    }

    public function test_the_books_table_is_reorderable_by_drag_and_drop(): void
    {
        $subject = Subject::factory()->create();

        $this->assertSame('order', $this->booksTable($subject)->instance()->getTable()->getReorderColumn());
    }

    public function test_dragging_a_book_row_persists_the_new_order(): void
    {
        $subject = Subject::factory()->create();
        $one = Book::factory()->order(1)->create(['subject_id' => $subject->id]);
        $two = Book::factory()->order(2)->create(['subject_id' => $subject->id]);
        $three = Book::factory()->order(3)->create(['subject_id' => $subject->id]);

        $this->booksTable($subject)
            ->call('reorderTable', [$three->getKey(), $one->getKey(), $two->getKey()]);

        $this->assertSame(1, $three->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);
        $this->assertSame(3, $two->refresh()->order);

        $this->assertSame(
            [$three->id, $one->id, $two->id],
            $subject->books()->pluck('id')->all(),
        );
    }

    public function test_reordering_one_subjects_books_never_touches_another_subject(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $one = Book::factory()->order(1)->create(['subject_id' => $subject->id]);
        $two = Book::factory()->order(2)->create(['subject_id' => $subject->id]);

        $otherOne = Book::factory()->order(1)->create(['subject_id' => $otherSubject->id]);
        $otherTwo = Book::factory()->order(2)->create(['subject_id' => $otherSubject->id]);

        $this->booksTable($subject)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $two->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);

        $this->assertSame(1, $otherOne->refresh()->order);
        $this->assertSame(2, $otherTwo->refresh()->order);
    }

    public function test_reordering_books_does_not_touch_the_subjects_chapters(): void
    {
        $subject = Subject::factory()->create();

        $bookOne = Book::factory()->order(1)->create(['subject_id' => $subject->id]);
        $bookTwo = Book::factory()->order(2)->create(['subject_id' => $subject->id]);

        $chapterOne = Chapter::factory()->order(1)->create(['subject_id' => $subject->id]);
        $chapterTwo = Chapter::factory()->order(2)->create(['subject_id' => $subject->id]);

        $this->booksTable($subject)
            ->call('reorderTable', [$bookTwo->getKey(), $bookOne->getKey()]);

        $this->assertSame(1, $chapterOne->refresh()->order);
        $this->assertSame(2, $chapterTwo->refresh()->order);
    }

    // ─── Books table in the subject workspace ───────────────────────────────

    public function test_the_books_table_renders_only_the_current_subjects_books(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $own = Book::factory()->create(['subject_id' => $subject->id]);
        $foreign = Book::factory()->create(['subject_id' => $otherSubject->id]);

        $this->booksTable($subject)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_the_books_table_shows_the_business_columns(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->content()->create([
            'subject_id' => $subject->id,
            'title' => 'Physiology Reader',
            'author' => 'Guyton',
        ]);
        BookPage::factory()->count(2)->create(['book_id' => $book->id]);

        $this->booksTable($subject)
            ->assertCanSeeTableRecords([$book])
            ->assertTableColumnStateSet('title', 'Physiology Reader', $book)
            ->assertTableColumnStateSet('author', 'Guyton', $book)
            ->assertTableColumnStateSet('type', BookType::Content, $book)
            ->assertTableColumnStateSet('status', $book->status, $book)
            ->assertTableColumnStateSet('content_state', '2 Pages', $book)
            ->assertTableColumnStateSet('order', $book->order, $book);
    }

    public function test_the_content_indicator_reports_the_pdf_file_state(): void
    {
        $subject = Subject::factory()->create();
        $withFile = Book::factory()->withPdfFile()->create(['subject_id' => $subject->id]);
        $withoutFile = Book::factory()->pdf()->create(['subject_id' => $subject->id]);

        $this->booksTable($subject)
            ->assertTableColumnStateSet('content_state', __('admin.learning.book_content_pdf_available'), $withFile)
            ->assertTableColumnStateSet('content_state', __('admin.learning.book_content_pdf_missing'), $withoutFile);
    }

    public function test_the_content_indicator_reports_the_page_count(): void
    {
        $subject = Subject::factory()->create();
        $empty = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $withOne = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $withThree = Book::factory()->content()->create(['subject_id' => $subject->id]);

        BookPage::factory()->create(['book_id' => $withOne->id]);
        BookPage::factory()->count(3)->create(['book_id' => $withThree->id]);

        $this->booksTable($subject)
            ->assertTableColumnStateSet('content_state', '0 Pages', $empty)
            ->assertTableColumnStateSet('content_state', '1 Page', $withOne)
            ->assertTableColumnStateSet('content_state', '3 Pages', $withThree);
    }

    /**
     * An empty book is a valid book, so the indicator describes what the book
     * holds without flagging it as a problem.
     */
    public function test_the_content_indicator_never_signals_an_error_for_an_empty_book(): void
    {
        $subject = Subject::factory()->create();
        $emptyContent = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $emptyPdf = Book::factory()->pdf()->create(['subject_id' => $subject->id]);
        $filledPdf = Book::factory()->withPdfFile()->create(['subject_id' => $subject->id]);

        $column = $this->booksTable($subject)->instance()->getTable()->getColumn('content_state');

        foreach ([$emptyContent, $emptyPdf] as $book) {
            $scoped = $column->record($book);
            $this->assertSame('gray', $scoped->getColor($scoped->getState()));
        }

        $scoped = $column->record($filledPdf);
        $this->assertSame('success', $scoped->getColor($scoped->getState()));
    }

    public function test_the_books_table_exposes_view_and_edit_actions(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->create(['subject_id' => $subject->id]);

        $this->booksTable($subject)
            ->assertActionExists(TestAction::make('view')->table($book))
            ->assertActionExists(TestAction::make('edit')->table($book));
    }

    public function test_the_book_view_and_edit_pages_load(): void
    {
        $book = Book::factory()->content()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewBook::class, ['record' => $book->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('book-overview')
            ->assertSee($book->title)
            ->assertSee($book->subject->name);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('author')
            ->assertFormFieldExists('type')
            ->assertFormFieldExists('status')
            ->assertFormFieldDoesNotExist('order');
    }

    public function test_book_can_be_edited(): void
    {
        $book = Book::factory()->content()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm([
                'title' => 'Renamed Book',
                'author' => 'New Author',
                'status' => ContentStatus::Draft->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'title' => 'Renamed Book',
            'author' => 'New Author',
            'order' => $book->order,
        ]);
    }

    public function test_books_are_not_registered_in_the_sidebar(): void
    {
        $this->assertFalse(BookResource::shouldRegisterNavigation());
    }
}
