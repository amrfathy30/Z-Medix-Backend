<?php

namespace Tests\Feature\Learning;

use App\Enums\BookType;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Models\Book;
use App\Models\Subject;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/**
 * The book's cover image, and the page count a PDF book carries.
 *
 * The cover is its own single-file media collection, so uploading, replacing or
 * removing one never touches the PDF beside it — and a book of either type may
 * have one.
 */
class BookCoverTest extends LearningTestCase
{
    private function booksTable(Subject $subject)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(BooksRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);
    }

    private function editBook(Book $book)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()]);
    }

    public function test_a_cover_can_be_uploaded_from_the_subjects_books_form(): void
    {
        $subject = Subject::factory()->create();

        $this->booksTable($subject)
            ->callAction(TestAction::make('create')->table(), [
                'title' => 'Netter Atlas',
                'type' => BookType::Pdf->value,
                'book_cover' => UploadedFile::fake()->image('cover.png'),
            ])
            ->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Netter Atlas')->sole();

        $this->assertTrue($book->hasCover());
        $this->assertSame(1, $book->getMedia(Book::COVER_COLLECTION)->count());
        // The PDF collection is untouched by a cover upload.
        $this->assertFalse($book->hasPdf());
    }

    /**
     * The page count is asked for PDF books only. A content book's pages are
     * counted from its own rows, so it is never asked for a figure.
     */
    public function test_a_pdf_books_page_count_is_entered_from_either_form(): void
    {
        $subject = Subject::factory()->create();

        // Entered while the book is created from the subject's Books table.
        $this->booksTable($subject)
            ->callAction(TestAction::make('create')->table(), [
                'title' => 'Netter Atlas',
                'type' => BookType::Pdf->value,
                'classification' => 'Fundamentals',
                'total_pages' => 640,
            ])
            ->assertHasNoActionErrors();

        $book = Book::query()->where('title', 'Netter Atlas')->sole();

        $this->assertSame(640, $book->total_pages);
        $this->assertSame('Fundamentals', $book->classification);

        $this->editBook($book)
            ->assertFormFieldExists('total_pages')
            ->fillForm(['total_pages' => 321])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(321, $book->refresh()->total_pages);

        // A content book is counted from its own page rows, so it is not asked.
        $this->editBook(Book::factory()->content()->create(['subject_id' => $subject->id]))
            ->assertFormFieldHidden('total_pages');
    }

    public function test_a_cover_can_be_uploaded_replaced_and_removed_from_the_book_form(): void
    {
        $book = Book::factory()->pdf()->create();

        $this->editBook($book)
            ->fillForm(['book_cover' => UploadedFile::fake()->image('first.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $book->refresh();
        $this->assertTrue($book->hasCover());
        $first = $book->getFirstMedia(Book::COVER_COLLECTION)->id;

        // Replaced: the collection holds one file, and it is the new one.
        $this->editBook($book)
            ->fillForm(['book_cover' => UploadedFile::fake()->image('second.webp')])
            ->call('save')
            ->assertHasNoFormErrors();

        $book->refresh();
        $this->assertSame(1, $book->getMedia(Book::COVER_COLLECTION)->count());
        $this->assertNotSame($first, $book->getFirstMedia(Book::COVER_COLLECTION)->id);

        $this->editBook($book)
            ->fillForm(['book_cover' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $book->refresh();
        $this->assertFalse($book->hasCover());
        $this->assertNull($book->coverUrl());
    }

    public function test_the_cover_collection_rejects_a_type_it_does_not_accept(): void
    {
        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/webp'],
            config('media.cover_image_mime_types'),
        );
    }
}
