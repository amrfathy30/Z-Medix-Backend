<?php

namespace Tests\Feature\Learning;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\BookResource\RelationManagers\PagesRelationManager;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Models\Book;
use App\Models\Subject;
use App\Services\Learning\BookService;
use Database\Factories\BookFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

class BookPdfTest extends LearningTestCase
{
    private const PDF_ACTIONS_COMPONENT = 'book-pdf-section.book-pdf-actions';

    private function booksTable(Subject $subject)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(BooksRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);
    }

    private function bookView(Book $book)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewBook::class, ['record' => $book->getRouteKey()]);
    }

    /**
     * The PDF actions live inside the book-pdf-actions component of the view
     * page's infolist, so they are addressed through it rather than by bare name.
     */
    private function openPdfAction(): TestAction
    {
        return TestAction::make('openPdf')->schemaComponent(self::PDF_ACTIONS_COMPONENT, 'infolist');
    }

    private function uploadPdfAction(): TestAction
    {
        return TestAction::make('uploadPdf')->schemaComponent(self::PDF_ACTIONS_COMPONENT, 'infolist');
    }

    // ─── Upload ─────────────────────────────────────────────────────────────

    public function test_a_pdf_can_be_uploaded_while_creating_the_book(): void
    {
        $subject = Subject::factory()->create();

        $this->booksTable($subject)
            ->callAction(TestAction::make('create')->table(), [
                'title' => 'Netter Atlas',
                'type' => BookType::Pdf->value,
                'book_pdf' => BookFactory::fakePdf('netter.pdf'),
            ])
            ->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Netter Atlas')->sole();

        $this->assertTrue($book->hasPdf());
        $this->assertSame(1, $book->getMedia(Book::PDF_COLLECTION)->count());
        $this->assertSame(ContentStatus::Draft, $book->status);
    }

    public function test_the_pdf_lives_in_the_book_pdf_collection(): void
    {
        $book = Book::factory()->withPdfFile()->create();

        $media = $book->getFirstMedia(Book::PDF_COLLECTION);

        $this->assertNotNull($media);
        $this->assertSame('book_pdf', $media->collection_name);
        $this->assertSame('application/pdf', $media->mime_type);
    }

    public function test_the_book_pdf_collection_is_single_file(): void
    {
        $book = Book::factory()->pdf()->create();

        $this->assertTrue(
            $book->getMediaCollection(Book::PDF_COLLECTION)->singleFile,
            'book_pdf must be registered as a single-file collection.',
        );
    }

    public function test_replacing_the_pdf_does_not_accumulate_media(): void
    {
        $book = Book::factory()->withPdfFile('first.pdf')->create();
        $firstMediaId = $book->getFirstMedia(Book::PDF_COLLECTION)->id;

        app(BookService::class)->replacePdf($book, BookFactory::fakePdf('second.pdf'));

        $book->refresh()->unsetRelation('media');

        $this->assertSame(1, $book->getMedia(Book::PDF_COLLECTION)->count());
        $this->assertNotSame($firstMediaId, $book->getFirstMedia(Book::PDF_COLLECTION)->id);
        $this->assertSame('second.pdf', $book->pdfFileName());
    }

    public function test_the_pdf_can_be_replaced_from_the_book_view(): void
    {
        $book = Book::factory()->withPdfFile('old.pdf')->create();

        $this->bookView($book)
            ->callAction($this->uploadPdfAction(), ['pdf' => BookFactory::fakePdf('new.pdf')])
            ->assertHasNoActionErrors();

        $book->refresh()->unsetRelation('media');

        $this->assertSame(1, $book->getMedia(Book::PDF_COLLECTION)->count());
        $this->assertSame('new.pdf', $book->pdfFileName());
    }

    public function test_the_pdf_is_served_through_the_media_url_helper(): void
    {
        $book = Book::factory()->withPdfFile()->create();

        $url = $book->pdfUrl();

        $this->assertNotNull($url);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $url);
        $this->assertStringContainsString('book.pdf', $url);
    }

    public function test_a_book_without_a_pdf_resolves_no_url(): void
    {
        $book = Book::factory()->pdf()->create();

        $this->assertNull($book->pdfUrl());
        $this->assertNull($book->pdfFileName());
    }

    // ─── Validation ─────────────────────────────────────────────────────────

    public function test_the_pdf_rules_come_from_the_shared_media_config(): void
    {
        $this->assertSame(config('media.document_mime_types'), BookResource::pdfMimeTypes());
        $this->assertSame(['application/pdf'], BookResource::pdfMimeTypes());
        $this->assertSame((int) config('media.max_document_size_kb'), BookResource::pdfMaxSizeKb());
    }

    public function test_a_non_pdf_upload_is_rejected(): void
    {
        $subject = Subject::factory()->create();

        $this->booksTable($subject)
            ->callAction(TestAction::make('create')->table(), [
                'title' => 'Not a PDF',
                'type' => BookType::Pdf->value,
                'book_pdf' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertHasActionErrors(['book_pdf']);

        $this->assertSame(0, $subject->books()->count());
    }

    public function test_an_oversized_pdf_is_rejected(): void
    {
        $book = Book::factory()->pdf()->create();
        $oversizedKb = BookResource::pdfMaxSizeKb() + 1024;

        $this->bookView($book)
            ->callAction($this->uploadPdfAction(), ['pdf' => BookFactory::fakePdf('huge.pdf', $oversizedKb)])
            ->assertHasActionErrors(['pdf']);

        $book->refresh()->unsetRelation('media');

        $this->assertFalse($book->hasPdf());
    }

    public function test_the_collection_itself_refuses_a_non_pdf_file(): void
    {
        $book = Book::factory()->pdf()->create();

        // Server-side defence: even bypassing the form, the collection's own
        // MIME check keeps a non-PDF out of book_pdf.
        $this->expectException(FileUnacceptableForCollection::class);

        app(BookService::class)->replacePdf($book, UploadedFile::fake()->image('cover.jpg'));
    }

    // ─── An empty PDF book is valid in any status ───────────────────────────

    public function test_a_pdf_book_may_exist_without_a_file(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        $this->assertFalse($book->hasPdf());

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm([
                'title' => 'Still fileless',
                'status' => ContentStatus::Draft->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Still fileless', $book->refresh()->title);
        $this->assertSame(ContentStatus::Draft, $book->status);
    }

    public function test_a_pdf_book_can_be_published_without_a_file(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
        $this->assertFalse($book->hasPdf());
    }

    public function test_a_pdf_book_can_be_archived_without_a_file(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Archived->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Archived, $book->refresh()->status);
    }

    public function test_a_pdf_can_still_be_uploaded_after_the_book_is_published(): void
    {
        $book = Book::factory()->pdf()->create(['status' => ContentStatus::Published]);

        $this->bookView($book)
            ->callAction($this->uploadPdfAction(), ['pdf' => BookFactory::fakePdf('late.pdf')])
            ->assertHasNoActionErrors();

        $book->refresh()->unsetRelation('media');

        $this->assertTrue($book->hasPdf());
        $this->assertSame('late.pdf', $book->pdfFileName());
        $this->assertSame(ContentStatus::Published, $book->status);
    }

    public function test_a_pdf_book_can_be_published_once_its_file_exists(): void
    {
        $book = Book::factory()->withPdfFile()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $book->refresh()->status);
        $this->assertTrue($book->hasPdf());
    }

    // ─── Book view ──────────────────────────────────────────────────────────

    public function test_the_pdf_book_view_shows_the_file_and_an_open_action(): void
    {
        $book = Book::factory()->withPdfFile('atlas.pdf')->create();

        $this->bookView($book)
            ->assertOk()
            ->assertSchemaComponentVisible('book-pdf-section')
            ->assertSchemaComponentVisible('book-pdf-section.book-pdf-summary')
            ->assertSchemaComponentHidden('book-pdf-section.book-pdf-missing-state')
            ->assertActionVisible($this->openPdfAction())
            ->assertActionVisible($this->uploadPdfAction())
            ->assertSee('atlas.pdf');
    }

    public function test_the_pdf_book_view_prompts_when_no_file_exists(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        $this->bookView($book)
            ->assertOk()
            ->assertSchemaComponentVisible('book-pdf-section')
            ->assertSchemaComponentVisible('book-pdf-section.book-pdf-missing-state')
            ->assertSchemaComponentHidden('book-pdf-section.book-pdf-summary')
            ->assertActionDoesNotExist($this->openPdfAction())
            ->assertActionVisible($this->uploadPdfAction())
            ->assertSee(__('admin.learning.book_pdf_missing_heading'));
    }

    public function test_a_pdf_book_never_exposes_a_pages_table(): void
    {
        $book = Book::factory()->withPdfFile()->create();

        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertFalse(PagesRelationManager::canViewForRecord($book, ViewBook::class));

        $this->bookView($book)
            ->assertOk()
            ->assertDontSeeLivewire(PagesRelationManager::class);
    }
}
