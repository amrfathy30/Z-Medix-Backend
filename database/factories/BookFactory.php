<?php

namespace Database\Factories;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Models\Book;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Testing\File;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'title' => fake()->unique()->sentence(3),
            'author' => fake()->name(),
            'type' => BookType::Content,
            'status' => ContentStatus::Published,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BookType::Pdf,
        ]);
    }

    public function content(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BookType::Content,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Archived,
        ]);
    }

    public function withoutAuthor(): static
    {
        return $this->state(fn (array $attributes) => [
            'author' => null,
        ]);
    }

    public function order(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }

    /**
     * A PDF book with its file already in the book_pdf collection.
     */
    public function withPdfFile(string $fileName = 'book.pdf'): static
    {
        return $this->pdf()->afterCreating(function (Book $book) use ($fileName): void {
            $book->addMedia(self::fakePdf($fileName))
                ->toMediaCollection(Book::PDF_COLLECTION);

            $book->unsetRelation('media');
        });
    }

    /**
     * An uploadable PDF whose bytes really do sniff as `application/pdf`, so it
     * passes the collection's own MIME check the way a real upload does.
     * `UploadedFile::fake()->create()` writes an empty file, which sniffs as
     * `application/x-empty` and would be refused.
     *
     * `$reportedKilobytes` overrides only the size validation sees, so an
     * oversized upload can be exercised without writing megabytes to disk.
     */
    public static function fakePdf(string $fileName = 'book.pdf', ?int $reportedKilobytes = null): File
    {
        $handle = tmpfile();
        fwrite($handle, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        $file = new File($fileName, $handle);
        $file->mimeTypeToReport = 'application/pdf';

        if ($reportedKilobytes !== null) {
            $file->sizeToReport = $reportedKilobytes * 1024;
        }

        return $file;
    }
}
