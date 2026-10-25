<?php

namespace App\Console\Commands;

use App\Enums\BookType;
use App\Models\Book;
use App\Services\Learning\BookPdfPageCountService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class BackfillBookPdfPageCounts extends Command
{
    protected $signature = 'books:backfill-pdf-page-counts
        {--dry-run : Extract and report counts without saving them}
        {--chunk=100 : Number of books to load per chunk}';

    protected $description = 'Backfill missing platform Book PDF counts without modifying PDF files';

    public function handle(BookPdfPageCountService $counter): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if ($chunk === false) {
            $this->error('The chunk size must be between 1 and 1000.');

            return self::INVALID;
        }

        $processed = $successful = $failed = $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');

        Book::query()
            ->where('type', BookType::Pdf)
            ->whereNull('pdf_pages_count')
            ->with('media')
            ->chunkById($chunk, function (Collection $books) use ($counter, $dryRun, &$processed, &$successful, &$failed, &$skipped): void {
                foreach ($books as $book) {
                    $processed++;
                    $media = $book->getFirstMedia(Book::PDF_COLLECTION);

                    if ($media === null) {
                        $skipped++;
                        $this->warn("Book {$book->id}: skipped (no PDF attachment).");

                        continue;
                    }

                    try {
                        $count = $counter->count($media);

                        if (! $dryRun) {
                            $updated = Book::query()
                                ->whereKey($book->id)
                                ->where('type', BookType::Pdf)
                                ->whereNull('pdf_pages_count')
                                ->whereHas('media', fn ($query) => $query->whereKey($media->id)->where('collection_name', Book::PDF_COLLECTION))
                                ->update(['pdf_pages_count' => $count]);

                            if ($updated === 0) {
                                $skipped++;
                                $this->warn("Book {$book->id}: skipped (record or attachment changed).");

                                continue;
                            }
                        }

                        $successful++;
                        $this->line("Book {$book->id}: {$count} pages".($dryRun ? ' (dry-run).' : '.'));
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->warn("Book {$book->id}: failed ({$exception->getMessage()}).");
                    }
                }
            });

        $this->info("Processed: {$processed}; successful: {$successful}; failed: {$failed}; skipped: {$skipped}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
