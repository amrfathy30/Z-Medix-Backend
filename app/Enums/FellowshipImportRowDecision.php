<?php

namespace App\Enums;

/**
 * The admin's choice for one duplicate row.
 *
 * Always per row: a decision belongs to a row and is stored on it. The review
 * screen can apply one to a ticked selection, which is a convenience over the
 * same per-row write and not a second path — a row the decision does not suit is
 * left pending rather than given something else. There is no decision an import
 * takes by default, and none it infers from the file.
 *
 * `UpdateExisting` is offered only against an existing stored question, never
 * against an earlier row of the same file, which has no question to update yet.
 */
enum FellowshipImportRowDecision: string
{
    case Skip = 'skip';
    case UpdateExisting = 'update_existing';
    case KeepBoth = 'keep_both';
}
