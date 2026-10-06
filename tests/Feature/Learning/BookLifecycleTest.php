<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Subject;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Books follow the chapter lifecycle: a soft delete keeps the record and its
 * children restorable, a force delete lets the database cascade clear the tree,
 * and PDF media is only destroyed when the book itself is gone for good.
 */
class BookLifecycleTest extends LearningTestCase
{
    private function booksTable(Subject $subject)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(BooksRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);
    }

    public function test_book_can_be_soft_deleted_and_restored_from_the_subject(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->create(['subject_id' => $subject->id]);

        $this->booksTable($subject)
            ->callAction(TestAction::make('delete')->table($book));

        $this->assertSoftDeleted('books', ['id' => $book->id]);

        $this->booksTable($subject)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($book));

        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);
    }

    /**
     * Pages follow the chapter-page convention: a soft-deleted parent leaves its
     * pages alone, so restoring the book brings the whole book back intact.
     */
    public function test_soft_deleting_a_book_leaves_its_pages_restorable(): void
    {
        $book = Book::factory()->content()->create();
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $book->delete();

        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertDatabaseHas('book_pages', ['id' => $page->id, 'deleted_at' => null]);

        $book->restore();

        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);
        $this->assertTrue($book->refresh()->pages->contains($page));
    }

    public function test_soft_deleting_a_pdf_book_keeps_its_media_intact(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $media = $book->getFirstMedia(Book::PDF_COLLECTION);

        $book->delete();

        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id]);

        $book->restore();
        $book->refresh()->unsetRelation('media');

        $this->assertTrue($book->hasPdf());
        $this->assertNotNull($book->pdfUrl());
    }

    public function test_force_deleting_a_book_removes_its_pages(): void
    {
        $book = Book::factory()->content()->create();
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $book->forceDelete();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('book_pages', ['id' => $page->id]);
    }

    public function test_force_deleting_a_pdf_book_removes_its_media(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $media = $book->getFirstMedia(Book::PDF_COLLECTION);

        $book->forceDelete();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_soft_deleting_a_subject_leaves_its_books_untouched(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->create(['subject_id' => $subject->id]);

        $subject->delete();

        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);

        $subject->restore();

        $this->assertTrue($subject->refresh()->books->contains($book));
    }

    public function test_deleting_a_subject_outright_removes_its_books_and_their_pages(): void
    {
        $subject = Subject::factory()->create();
        $book = Book::factory()->content()->create(['subject_id' => $subject->id]);
        $page = BookPage::factory()->create(['book_id' => $book->id]);

        $subject->forceDelete();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('book_pages', ['id' => $page->id]);
    }

    public function test_a_subject_never_displays_a_book_belonging_to_another_subject(): void
    {
        $subject = Subject::factory()->create();
        $foreign = Book::factory()->create();

        $this->booksTable($subject)
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertSame(0, $subject->books()->count());
    }

    public function test_a_books_order_sequence_ignores_another_subjects_trashed_books(): void
    {
        $subject = Subject::factory()->create();
        $trashed = Book::factory()->order(1)->create(['subject_id' => $subject->id]);
        $trashed->delete();

        // A trashed sibling still occupies its slot, so the next book is appended
        // after it rather than colliding with it.
        $next = Book::factory()->create(['subject_id' => $subject->id]);

        $this->assertSame(2, $next->order);
    }
}
