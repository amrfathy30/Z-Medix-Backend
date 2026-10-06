<?php

namespace Tests\Feature\Learning;

use App\Enums\BookType;
use App\Exceptions\Learning\BookTypeLockedException;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Models\Book;
use App\Models\BookPage;
use Livewire\Livewire;

/**
 * A book's type decides which kind of content it may hold, so it is frozen once
 * content exists. The guard is on the model, not only on the admin form.
 */
class BookTypeChangeTest extends LearningTestCase
{
    public function test_an_empty_draft_book_may_change_its_type(): void
    {
        $book = Book::factory()->content()->draft()->create();

        $this->assertTrue($book->canChangeType());

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm(['type' => BookType::Pdf->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(BookType::Pdf, $book->refresh()->type);
    }

    public function test_an_empty_pdf_book_may_become_a_content_book(): void
    {
        $book = Book::factory()->pdf()->draft()->create();

        $this->assertTrue($book->canChangeType());

        $book->update(['type' => BookType::Content]);

        $this->assertSame(BookType::Content, $book->refresh()->type);
    }

    public function test_a_pdf_book_with_a_file_cannot_become_a_content_book(): void
    {
        $book = Book::factory()->withPdfFile()->draft()->create();

        $this->assertFalse($book->canChangeType());

        $this->expectException(BookTypeLockedException::class);
        $this->expectExceptionMessage('This book already has a PDF file, so its type can no longer be changed.');

        $book->update(['type' => BookType::Content]);
    }

    public function test_a_content_book_with_pages_cannot_become_a_pdf_book(): void
    {
        $book = Book::factory()->content()->draft()->create();
        BookPage::factory()->create(['book_id' => $book->id]);

        $this->assertFalse($book->canChangeType());

        $this->expectException(BookTypeLockedException::class);
        $this->expectExceptionMessage('This book already has pages, so its type can no longer be changed.');

        $book->update(['type' => BookType::Pdf]);
    }

    public function test_the_type_stays_put_when_the_guard_refuses_the_change(): void
    {
        $book = Book::factory()->withPdfFile()->draft()->create();

        try {
            $book->update(['type' => BookType::Content]);
        } catch (BookTypeLockedException) {
            // Expected; the assertion below is the point.
        }

        $this->assertDatabaseHas('books', ['id' => $book->id, 'type' => 'pdf']);
    }

    public function test_the_guard_holds_even_when_the_form_restriction_is_bypassed(): void
    {
        $book = Book::factory()->content()->draft()->create();
        BookPage::factory()->create(['book_id' => $book->id]);

        // Writing the raw column directly, as a crafted request would.
        $this->expectException(BookTypeLockedException::class);

        $book->forceFill(['type' => BookType::Pdf->value])->save();
    }

    public function test_the_type_field_is_disabled_once_content_exists(): void
    {
        $withFile = Book::factory()->withPdfFile()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $withFile->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDisabled('type');

        $empty = Book::factory()->content()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $empty->getRouteKey()])
            ->assertOk()
            ->assertFormFieldEnabled('type');
    }

    public function test_saving_other_fields_is_still_allowed_while_the_type_is_frozen(): void
    {
        $book = Book::factory()->withPdfFile()->draft()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditBook::class, ['record' => $book->getRouteKey()])
            ->fillForm([
                'title' => 'Renamed while frozen',
                'author' => 'Someone Else',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $book->refresh();

        $this->assertSame('Renamed while frozen', $book->title);
        $this->assertSame('Someone Else', $book->author);
        $this->assertSame(BookType::Pdf, $book->type);
    }
}
