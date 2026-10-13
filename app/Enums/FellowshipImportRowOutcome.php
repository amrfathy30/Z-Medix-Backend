<?php

namespace App\Enums;

/**
 * What confirmation actually did with one row. Written once, together with the
 * row's `processed_at`, so a retried confirmation can tell finished work from
 * work still to do.
 */
enum FellowshipImportRowOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
