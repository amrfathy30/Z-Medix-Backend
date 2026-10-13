<?php

namespace App\Enums;

/**
 * Lifecycle of one question import.
 *
 * `Staged` is the whole preview: rows are validated and duplicate-matched, but
 * no question and no filter has been written yet. `Processing` is held for the
 * duration of confirmation so a second confirmation of the same import cannot
 * run alongside the first, and a confirmation interrupted part way can be
 * resumed instead of double-inserting.
 */
enum FellowshipImportStatus: string
{
    case Draft = 'draft';
    case Staged = 'staged';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
