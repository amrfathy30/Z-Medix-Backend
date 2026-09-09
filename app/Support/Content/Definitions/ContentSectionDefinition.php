<?php

namespace App\Support\Content\Definitions;

/**
 * Describes one section of a page: its item definitions and the default
 * data a seeder should write the first time the section row is created.
 */
class ContentSectionDefinition
{
    /**
     * @param  list<ContentItemDefinition>  $items
     * @param  array<string, mixed>  $defaultData
     */
    public function __construct(
        public readonly string $sectionKey,
        public readonly string $labelEn,
        public readonly string $labelAr,
        public readonly array $items = [],
        public readonly array $defaultData = [],
        public readonly int $sortOrder = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(array $attributes): self
    {
        return new self(
            sectionKey: $attributes['section_key'],
            labelEn: $attributes['label_en'],
            labelAr: $attributes['label_ar'],
            items: $attributes['items'] ?? [],
            defaultData: $attributes['default_data'] ?? [],
            sortOrder: $attributes['sort_order'] ?? 0,
        );
    }

    /** @return list<ContentItemDefinition> */
    public function orderedItems(): array
    {
        $items = $this->items;

        usort($items, fn (ContentItemDefinition $a, ContentItemDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $items;
    }
}
