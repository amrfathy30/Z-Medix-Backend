<?php

namespace App\Services\Learning;

use App\Models\Book;
use App\Models\Subject;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The supported domain path for creating a platform book and managing its PDF.
 *
 * The book's status is taken as given: it is an administrative state, not a
 * readiness check, so a book may be created published or archived while it is
 * still empty. Omitting it falls back to the column's `draft` default.
 */
class BookService
{
    public function __construct(private MediaUploadService $media) {}

    /**
     * @param  array<string, mixed>  $attributes  title and type, and optionally author, status and order
     */
    public function create(Subject $subject, array $attributes): Book
    {
        /** @var Book $book */
        $book = $subject->books()->create($attributes);

        return $book;
    }

    /**
     * Store or replace the book's single PDF. The collection is registered with
     * singleFile(), so the previous file is removed rather than accumulated.
     */
    public function replacePdf(Book $book, UploadedFile|string $file): Media
    {
        return $this->media->replace($book, $file, Book::PDF_COLLECTION);
    }

    public function removePdf(Book $book): void
    {
        $this->media->clearCollection($book, Book::PDF_COLLECTION);
    }
}
