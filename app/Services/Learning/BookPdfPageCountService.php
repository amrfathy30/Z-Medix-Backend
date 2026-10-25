<?php

namespace App\Services\Learning;

use App\Models\Book;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Process\Process;
use Throwable;

class BookPdfPageCountService
{
    public function count(Media $media): int
    {
        if (config("filesystems.disks.{$media->disk}.driver") !== 'local') {
            throw new RuntimeException('PDF page counting requires a local media disk.');
        }

        $path = $media->getPath();

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The PDF file is missing or unreadable.');
        }

        $process = new Process(['pdfinfo', $path], env: ['LC_ALL' => 'C']);
        $process->setTimeout(30);
        $process->mustRun();

        if (! preg_match('/^Pages:\s+([1-9][0-9]*)\s*$/m', $process->getOutput(), $matches)
            || (float) $matches[1] > 4294967295) {
            throw new RuntimeException('The PDF page count could not be determined.');
        }

        return (int) $matches[1];
    }

    public function added(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        if ($media->collection_name !== Book::PDF_COLLECTION || ! $media->model instanceof Book) {
            return;
        }

        $book = $media->model;

        try {
            $count = $this->count($media);
        } catch (Throwable $exception) {
            // This event precedes singleFile cleanup: discard only the rejected upload.
            $media->delete();
            report($exception);

            $message = __('The PDF page count could not be read. Upload a readable, supported PDF and try again.');

            throw ValidationException::withMessages(['pdf' => $message, 'book_pdf' => $message]);
        }

        $book->forceFill(['pdf_pages_count' => $count])->saveQuietly();
    }

    public function deleted(Media $media): void
    {
        if ($media->collection_name !== Book::PDF_COLLECTION || ! $media->model instanceof Book) {
            return;
        }

        $book = $media->model;

        if (! $book->media()->where('collection_name', Book::PDF_COLLECTION)->exists()) {
            $book->forceFill(['pdf_pages_count' => null])->saveQuietly();
        }
    }
}
