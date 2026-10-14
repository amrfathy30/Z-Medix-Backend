<?php

namespace App\Enums;

/**
 * What staging concluded about one spreadsheet row.
 *
 * `Invalid` rows are reported and excluded; they never block the valid rows
 * around them. `Duplicate` rows are valid but need the admin's per-row
 * decision before confirmation will act on them.
 */
enum FellowshipImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
}
