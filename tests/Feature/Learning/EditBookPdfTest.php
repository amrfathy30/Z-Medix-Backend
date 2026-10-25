<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Models\Book;
use App\Services\Learning\BookService;
use App\Services\Media\MediaUploadService;
use Database\Factories\BookFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\SubscribesStudents;

class EditBookPdfTest extends LearningTestCase
{
    use SubscribesStudents;

    public function test_edit_displays_the_extracted_count_and_attached_filename(): void
    {
        $book = Book::factory()->pdf()->create(['total_pages' => null]);
        app(BookService::class)->replacePdf($book, BookFactory::fakePdf('four-pages.pdf', pages: 4));

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->assertFormFieldDisabled('pdf_pages_count')
            ->assertFormSet(['pdf_pages_count' => 4])
            ->assertSee('four-pages.pdf')
            ->assertActionVisible('uploadPdf');
    }

    public function test_edit_replacement_refreshes_count_and_filename_without_discarding_unsaved_edits(): void
    {
        $book = Book::factory()->withPdfFile('old.pdf')->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['title' => 'Unsaved title', 'author' => 'Unsaved author'])
            ->callAction('uploadPdf', ['pdf' => BookFactory::fakePdf('new.pdf', pages: 4)])
            ->assertHasNoActionErrors()
            ->assertFormSet(['pdf_pages_count' => 4, 'title' => 'Unsaved title', 'author' => 'Unsaved author'])
            ->assertSee('new.pdf')
            ->assertDontSee('old.pdf')
            ->call('save')
            ->assertHasNoFormErrors();

        $book->refresh();
        $this->assertSame(4, $book->pdf_pages_count);
        $this->assertSame('Unsaved title', $book->title);
        $this->assertSame('Unsaved author', $book->author);

        Sanctum::actingAs($this->subscribedStudent());
        $this->getJson("/api/student/study/books/{$book->id}")->assertOk()
            ->assertJsonPath('data.pages_count', 4)
            ->assertJsonMissingPath('data.pdf_pages_count');
    }

    public function test_ordinary_edits_and_tampered_count_state_cannot_clear_or_override_the_count(): void
    {
        $book = Book::factory()->withPdfFile()->create();

        foreach ([null, 999] as $submittedCount) {
            Livewire::actingAs($this->superAdmin(), 'admin')
                ->test(EditBook::class, ['record' => $book->getRouteKey()])
                ->fillForm(['title' => 'Updated title', 'author' => 'Updated author', 'pdf_pages_count' => $submittedCount])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(1, $book->fresh()->pdf_pages_count);
        }
    }

    public function test_both_pages_replace_pdfs_through_the_same_service(): void
    {
        foreach ([EditBook::class, ViewBook::class] as $page) {
            $book = Book::factory()->withPdfFile()->create();
            $original = $book->getFirstMedia(Book::PDF_COLLECTION);
            $action = $page === EditBook::class
                ? TestAction::make('uploadPdf')
                : TestAction::make('uploadPdf')->schemaComponent('book-pdf-section.book-pdf-actions', 'infolist');
            $books = new BookService(app(MediaUploadService::class));
            $this->mock(BookService::class)
                ->shouldReceive('replacePdf')->once()->andReturnUsing($books->replacePdf(...));

            Livewire::actingAs($this->superAdmin(), 'admin')
                ->test($page, ['record' => $book->getRouteKey()])
                ->callAction($action, ['pdf' => BookFactory::fakePdf('replacement.pdf', pages: 3)])
                ->assertHasNoActionErrors();

            $this->assertSame(3, $book->fresh()->pdf_pages_count);
            $this->assertFileDoesNotExist($original->getPath());
        }
    }

    public function test_failed_replacement_from_edit_preserves_the_previous_file_count_and_form_state(): void
    {
        $book = Book::factory()->withPdfFile('original.pdf')->create();
        $original = $book->getFirstMedia(Book::PDF_COLLECTION);
        $bytes = file_get_contents($original->getPath());

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->callAction('uploadPdf', [
                'pdf' => UploadedFile::fake()->createWithContent('corrupt.pdf', '%PDF-1.4 broken'),
            ])
            ->assertHasActionErrors(['pdf'])
            ->assertFormSet(['pdf_pages_count' => 1])
            ->assertSee('original.pdf');

        $this->assertSame(1, $book->fresh()->pdf_pages_count);
        $this->assertSame($original->id, $book->fresh()->getFirstMedia(Book::PDF_COLLECTION)->id);
        $this->assertSame($bytes, file_get_contents($original->getPath()));
    }

    public function test_edit_upload_validation_and_content_book_visibility_are_preserved(): void
    {
        $book = Book::factory()->withPdfFile()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->callAction('uploadPdf', ['pdf' => UploadedFile::fake()->image('not-a-pdf.jpg')])
            ->assertHasActionErrors(['pdf']);
        $this->assertSame(1, $book->fresh()->pdf_pages_count);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => Book::factory()->content()->create()->getRouteKey()])
            ->assertActionHidden('uploadPdf')
            ->assertFormFieldHidden('pdf_pages_count');
    }
}
