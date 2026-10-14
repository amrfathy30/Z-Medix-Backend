<?php

namespace App\Services\Fellowship;

use Maatwebsite\Excel\Concerns\WithLimit;

/**
 * Hands the first sheet back as plain rows, headers included.
 *
 * Deliberately header-agnostic: the Excel package's own heading-row handling
 * would slugify column names, and the import wizard needs them exactly as the
 * admin sees them in their file in order to map them.
 *
 * The limit is one row past the configured ceiling plus the header, so staging
 * can tell a file that merely reaches the limit from one that exceeds it.
 */
class RawSheetImport implements WithLimit
{
    public function limit(): int
    {
        return ((int) config('fellowship.import.max_rows', 5000)) + 2;
    }
}
