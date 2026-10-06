<?php

namespace App\Exceptions\Learning;

use RuntimeException;

/**
 * Raised when a book's type would change after content exists under it. The
 * message is the business-facing wording the admin UI surfaces verbatim.
 */
class BookTypeLockedException extends RuntimeException
{
    public static function pdfAlreadyUploaded(): self
    {
        return new self('This book already has a PDF file, so its type can no longer be changed.');
    }

    public static function pagesAlreadyAdded(): self
    {
        return new self('This book already has pages, so its type can no longer be changed.');
    }
}
