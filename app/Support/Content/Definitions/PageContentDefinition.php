<?php

namespace App\Support\Content\Definitions;

use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;

/**
 * Describes one page and the sections it owns. Generic core class — must
 * never reference product-specific models or content.
 */
class PageContentDefinition
{
    /**
     * @param  array<string, ContentSectionDefinition>  $sections  Keyed by section_key.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelEn,
        public readonly string $labelAr,
        public readonly array $sections = [],
    ) {}

    /**
     * @param  list<ContentSectionDefinition>  $sections
     */
    public static function make(string $key, string $labelEn, string $labelAr, array $sections): self
    {
        $keyed = [];
        foreach ($sections as $section) {
            $keyed[$section->sectionKey] = $section;
        }

        return new self($key, $labelEn, $labelAr, $keyed);
    }

    public function hasSection(string $sectionKey): bool
    {
        return array_key_exists($sectionKey, $this->sections);
    }

    public function section(string $sectionKey): ContentSectionDefinition
    {
        if (! $this->hasSection($sectionKey)) {
            throw MissingContentDefinitionException::forSection($this->key, $sectionKey);
        }

        return $this->sections[$sectionKey];
    }

    /** @return list<ContentSectionDefinition> */
    public function orderedSections(): array
    {
        $sections = array_values($this->sections);

        usort($sections, fn (ContentSectionDefinition $a, ContentSectionDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $sections;
    }
}
