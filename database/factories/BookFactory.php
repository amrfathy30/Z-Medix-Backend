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
    public static function fakePdf(string $fileName = 'book.pdf', ?int $reportedKilobytes = null, int $pages = 1): File
    {
        $handle = tmpfile();
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $page): string => ($page + 2).' 0 R', range(1, $pages))).'] /Count '.$pages.' >>',
        ];

        for ($page = 1; $page <= $pages; $page++) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> >>';
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Size '.count($offsets).' /Root 1 0 R >>'."\nstartxref\n{$xref}\n%%EOF\n";
        fwrite($handle, $pdf);

        $file = new File($fileName, $handle);
        $file->mimeTypeToReport = 'application/pdf';

        if ($reportedKilobytes !== null) {
            $file->sizeToReport = $reportedKilobytes * 1024;
        }

        return $file;
    }
}
