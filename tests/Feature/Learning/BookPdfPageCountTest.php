<?php

namespace Tests\Feature\Learning;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Subject;
use App\Services\Learning\BookPdfPageCountService;
use App\Services\Learning\BookService;
use Database\Factories\BookFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Study\StudyTestCase;

class BookPdfPageCountTest extends StudyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('filament_public');
    }

    public function test_upload_and_replacement_extract_real_counts_and_delete_only_the_old_file(): void
    {
        $book = Book::factory()->pdf()->create(['total_pages' => 999]);
        $service = app(BookService::class);
        $first = $service->replacePdf($book, BookFactory::fakePdf(pages: 2));

        $this->assertSame(2, $book->fresh()->pdf_pages_count);
        $firstPath = $first->getPath();
        $this->assertFileExists($firstPath);

        $second = $service->replacePdf($book, BookFactory::fakePdf('replacement.pdf', pages: 5));

        $this->assertSame(5, $book->fresh()->pdf_pages_count);
        $this->assertSame(999, $book->fresh()->total_pages);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists($second->getPath());
        $this->assertSame(1, $book->fresh()->getMedia(Book::PDF_COLLECTION)->count());
    }

    public function test_corrupt_pdf_replacement_preserves_the_existing_pdf_and_count(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $original = $book->getFirstMedia(Book::PDF_COLLECTION);
        $bytes = file_get_contents($original->getPath());
        $corrupt = UploadedFile::fake()->createWithContent('corrupt.pdf', "%PDF-1.4\ncorrupt\n%%EOF");

        try {
            app(BookService::class)->replacePdf($book, $corrupt);
            $this->fail('A corrupt PDF must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pdf', $exception->errors());
        }

        $book->refresh();
        $this->assertSame(1, $book->pdf_pages_count);
        $this->assertSame($original->id, $book->getFirstMedia(Book::PDF_COLLECTION)->id);
        $this->assertSame($bytes, file_get_contents($original->getPath()));
        $this->assertSame(1, $book->getMedia(Book::PDF_COLLECTION)->count());
    }

    public function test_an_unavailable_extractor_preserves_the_existing_pdf_and_count(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $original = $book->getFirstMedia(Book::PDF_COLLECTION);
        $this->partialMock(BookPdfPageCountService::class, function ($mock): void {
            $mock->shouldReceive('count')->once()->andThrow(new RuntimeException('pdfinfo unavailable'));
        });

        try {
            app(BookService::class)->replacePdf($book, BookFactory::fakePdf());
            $this->fail('Extraction failure must reject the new upload.');
        } catch (ValidationException) {
            $this->assertSame(1, $book->fresh()->pdf_pages_count);
            $this->assertSame($original->id, $book->fresh()->getFirstMedia(Book::PDF_COLLECTION)->id);
            $this->assertFileExists($original->getPath());
        }
    }

    public function test_a_corrupt_initial_upload_leaves_no_attachment_or_invented_count(): void
    {
        $book = Book::factory()->pdf()->create();

        try {
            $book->addMedia(UploadedFile::fake()->createWithContent('bad.pdf', '%PDF-1.4 broken'))
                ->toMediaCollection(Book::PDF_COLLECTION);
            $this->fail('A corrupt PDF must be rejected.');
        } catch (ValidationException) {
            $this->assertNull($book->fresh()->pdf_pages_count);
            $this->assertFalse($book->fresh()->hasPdf());
        }
    }

    public function test_pdf_deletion_clears_the_count_but_cover_deletion_does_not(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $cover = $book->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection(Book::COVER_COLLECTION);
        $cover->delete();
        $this->assertSame(1, $book->fresh()->pdf_pages_count);

        $book->getFirstMedia(Book::PDF_COLLECTION)->delete();
        $this->assertNull($book->fresh()->pdf_pages_count);

        app(BookService::class)->replacePdf($book->refresh(), BookFactory::fakePdf(pages: 3));
        app(BookService::class)->removePdf($book->refresh());
        $this->assertNull($book->fresh()->pdf_pages_count);
        $this->assertFalse($book->fresh()->hasPdf());
    }

    public function test_student_endpoints_use_stored_counts_without_extracting_pdfs(): void
    {
        $subject = Subject::factory()->create();
        $pdf = Book::factory()->pdf()->create(['subject_id' => $subject->id, 'pdf_pages_count' => 12, 'total_pages' => 999, 'order' => 1]);
        $content = Book::factory()->content()->create(['subject_id' => $subject->id, 'order' => 2]);
        BookPage::factory()->count(3)->create(['book_id' => $content->id]);
        BookPage::factory()->create(['book_id' => $pdf->id]);
        $unknown = Book::factory()->pdf()->create(['subject_id' => $subject->id, 'order' => 3, 'total_pages' => 500]);
        $this->mock(BookPdfPageCountService::class)->shouldNotReceive('count');
        Sanctum::actingAs($this->student());

        foreach (['/api/student/books', $this->studyUrl."/subjects/{$subject->id}/books"] as $url) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('data.0.pages_count', 12)
                ->assertJsonPath('data.1.pages_count', 3)
                ->assertJsonPath('data.2.pages_count', null)
                ->assertJsonMissingPath('data.0.pdf_pages_count');
        }

        foreach ([[$pdf, 12], [$content, 3], [$unknown, null]] as [$book, $expected]) {
            $this->getJson($this->studyUrl."/books/{$book->id}")->assertOk()
                ->assertJsonPath('data.pages_count', $expected)
                ->assertJsonMissingPath('data.pdf_pages_count');
        }

        $this->getJson($this->studyUrl."/subjects/{$subject->id}")->assertOk()
            ->assertJsonPath('data.books.0.pages_count', 12)
            ->assertJsonPath('data.books.1.pages_count', 3)
            ->assertJsonPath('data.books.2.pages_count', null);
    }

    public function test_backfill_dry_run_is_read_only_and_real_run_is_repeatable(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $book->forceFill(['pdf_pages_count' => null])->saveQuietly();
        $media = $book->getFirstMedia(Book::PDF_COLLECTION);
        $bytes = file_get_contents($media->getPath());
        $known = Book::factory()->pdf()->create(['pdf_pages_count' => 25]);
        $content = Book::factory()->content()->create();
        Book::factory()->pdf()->create();
        $missing = Book::factory()->withPdfFile('missing.pdf')->create();
        $missing->forceFill(['pdf_pages_count' => null])->saveQuietly();
        unlink($missing->getFirstMedia(Book::PDF_COLLECTION)->getPath());

        $this->artisan('books:backfill-pdf-page-counts', ['--dry-run' => true, '--chunk' => 1])
            ->expectsOutput('Processed: 3; successful: 1; failed: 1; skipped: 1.')
            ->assertExitCode(1);
        $this->assertNull($book->fresh()->pdf_pages_count);

        $this->artisan('books:backfill-pdf-page-counts', ['--chunk' => 1])
            ->expectsOutput('Processed: 3; successful: 1; failed: 1; skipped: 1.')
            ->assertExitCode(1);
        $this->assertSame(1, $book->fresh()->pdf_pages_count);
        $this->assertSame(25, $known->fresh()->pdf_pages_count);
        $this->assertNull($content->fresh()->pdf_pages_count);
        $this->assertNull($missing->fresh()->pdf_pages_count);
        $this->assertSame($bytes, file_get_contents($media->getPath()));

        $this->artisan('books:backfill-pdf-page-counts', ['--dry-run' => true])
            ->expectsOutput('Processed: 2; successful: 0; failed: 1; skipped: 1.')
            ->assertExitCode(1);
    }

    public function test_backfill_does_not_overwrite_a_count_saved_while_extraction_is_running(): void
    {
        $book = Book::factory()->withPdfFile()->create();
        $book->forceFill(['pdf_pages_count' => null])->saveQuietly();
        $this->mock(BookPdfPageCountService::class, function ($mock) use ($book): void {
            $mock->shouldReceive('count')->once()->andReturnUsing(function () use ($book): int {
                $book->forceFill(['pdf_pages_count' => 9])->saveQuietly();

                return 1;
            });
        });

        $this->artisan('books:backfill-pdf-page-counts')
            ->expectsOutput('Processed: 1; successful: 0; failed: 0; skipped: 1.')
            ->assertSuccessful();
        $this->assertSame(9, $book->fresh()->pdf_pages_count);
    }

    public function test_backfill_rejects_invalid_chunk_sizes(): void
    {
        $this->artisan('books:backfill-pdf-page-counts', ['--chunk' => 0])->assertExitCode(2);
    }
}
