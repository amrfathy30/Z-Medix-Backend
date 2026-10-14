<?php

namespace App\Enums;

/**
 * Where a duplicate row's twin lives: an earlier row of the same upload, or a
 * question already stored in this Fellowship.
 */
enum FellowshipImportDuplicateScope: string
{
    case File = 'file';
    case Existing = 'existing';
}
