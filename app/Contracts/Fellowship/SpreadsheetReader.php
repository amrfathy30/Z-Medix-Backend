<?php

namespace App\Contracts\Fellowship;

/**
 * Reads an uploaded workbook into a header row and data rows.
 *
 * Behind an interface because the importer's rules — required mappings, row
 * validation, duplicate detection — are the part worth testing closely, and
 * they have nothing to do with which library opened the file.
 */
interface SpreadsheetReader
{
    /**
     * The sheet's header row: the column names exactly as the file spells them,
     * in column order, with blank trailing columns dropped.
     *
     * @return list<string>
     */
    public function headers(string $path): array;

    /**
     * The data rows, each keyed by header. Rows that are entirely empty are
     * dropped — a trailing blank line in a spreadsheet is not a question.
     *
     * Every cell is returned as a trimmed string or null: one header, one value,
     * with no multi-value parsing.
     *
     * @return list<array<string, string|null>>
     */
    public function rows(string $path): array;
}
