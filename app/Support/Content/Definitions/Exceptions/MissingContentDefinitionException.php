<?php

namespace App\Support\Content\Definitions\Exceptions;

use RuntimeException;

/**
 * Thrown whenever a page or section is requested from the
 * ContentDefinitionRegistry without a matching definition. There is no
 * fallback by type — a missing definition is always a developer error.
 */
class MissingContentDefinitionException extends RuntimeException
{
    public static function forPage(string $pageKey): self
    {
        return new self("Missing content definition for page {$pageKey}.");
    }

    public static function forSection(string $pageKey, string $sectionKey): self
    {
        return new self("Missing content definition for page {$pageKey}, section {$sectionKey}.");
    }
}
