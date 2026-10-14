<?php

namespace App\Support\Fellowship;

use App\Exceptions\Fellowship\FellowshipImportException;

/**
 * Whether a stored path is one this module's own upload step produced.
 *
 * The wizard's upload field is Livewire state, and Livewire state is the
 * client's to set. Once the field has been dehydrated its value is an ordinary
 * string — the path Filament moved the upload to — and nothing about a string
 * arriving from the browser proves it is that path rather than any other file on
 * the import disk. A crafted request could otherwise name a file belonging to
 * something else entirely, and the wizard would read it, store its header row,
 * show its contents as column samples and stage every cell into
 * `fellowship_import_rows`: an admin who may import questions would be able to
 * read any private file the disk holds.
 *
 * So the path is checked rather than trusted, against the two things the upload
 * step actually guarantees: it lands in the import directory, and it carries one
 * of the spreadsheet extensions the field accepts. Both are read from config, so
 * the field and this check cannot drift apart.
 *
 * Traversal is refused here rather than left to Flysystem. Flysystem does detect
 * it, but by throwing from inside an existence check — an unhandled error rather
 * than the wizard's own "upload that again" — and {@see
 * \Illuminate\Filesystem\FilesystemAdapter::path()} does no normalising at all,
 * so a `..` reaching a `path()` call would escape the disk root outright.
 */
final class ImportFilePath
{
    /**
     * The path, if it is one the upload step could have produced; null for
     * anything else, including a path that is merely absent.
     */
    public static function sanitize(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        // A null byte truncates the name every filesystem call below sees, so a
        // path carrying one is never the one that was checked.
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        // Rooted paths and traversal both leave the directory this module owns.
        if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        $directory = trim((string) config('fellowship.import.directory', 'fellowship-imports'), '/');

        if ($directory === '' || ! str_starts_with($path, $directory.'/')) {
            return null;
        }

        // Directly inside it: the field stores flat, so a nested path is not
        // something the upload step produces.
        $name = substr($path, strlen($directory) + 1);

        if ($name === '' || str_contains($name, '/')) {
            return null;
        }

        /** @var list<string> $extensions */
        $extensions = (array) config('fellowship.import.extensions', ['xlsx', 'xls', 'csv']);

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === '' || ! in_array($extension, array_map('strtolower', $extensions), true)) {
            return null;
        }

        return $path;
    }

    /**
     * The same check, for the callers that have no better answer than to stop.
     *
     * @throws FellowshipImportException
     */
    public static function assert(mixed $path): string
    {
        $sanitized = self::sanitize($path);

        if ($sanitized === null) {
            throw FellowshipImportException::unreadableFile('The upload is no longer available.');
        }

        return $sanitized;
    }
}
