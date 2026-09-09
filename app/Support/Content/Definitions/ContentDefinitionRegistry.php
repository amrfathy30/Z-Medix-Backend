<?php

namespace App\Support\Content\Definitions;

use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;

/**
 * Source of truth for Website Content page/section structure. Both the
 * seeder and the (future) admin form generator read from this registry
 * instead of hardcoding page/section shape. Generic core class — must
 * never reference product-specific models or content.
 */
class ContentDefinitionRegistry
{
    /** @var array<string, PageContentDefinition> */
    private array $pages = [];

    public function register(PageContentDefinition $page): static
    {
        $this->pages[$page->key] = $page;

        return $this;
    }

    public function has(string $pageKey): bool
    {
        return array_key_exists($pageKey, $this->pages);
    }

    public function hasSection(string $pageKey, string $sectionKey): bool
    {
        return $this->has($pageKey) && $this->pages[$pageKey]->hasSection($sectionKey);
    }

    public function forPage(string $pageKey): PageContentDefinition
    {
        if (! $this->has($pageKey)) {
            throw MissingContentDefinitionException::forPage($pageKey);
        }

        return $this->pages[$pageKey];
    }

    public function forSection(string $pageKey, string $sectionKey): ContentSectionDefinition
    {
        return $this->forPage($pageKey)->section($sectionKey);
    }

    /** @return list<PageContentDefinition> */
    public function allPages(): array
    {
        return array_values($this->pages);
    }
}
