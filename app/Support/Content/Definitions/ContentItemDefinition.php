<?php

namespace App\Support\Content\Definitions;

use App\Support\Content\Enums\ContentInputType;

/**
 * Describes a single content field inside a section: its input type, labels,
 * and layout. No `required` flag on purpose — validation is derived from
 * `type` alone via ContentInputValidationMapper.
 */
class ContentItemDefinition
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<ContentItemDefinition>  $schema  Nested item definitions, only meaningful when type is Repeater.
     */
    public function __construct(
        public readonly string $key,
        public readonly ContentInputType $type,
        public readonly string $labelEn,
        public readonly string $labelAr,
        public readonly ?string $helpEn = null,
        public readonly ?string $helpAr = null,
        public readonly bool $translatable = false,
        public readonly int $sortOrder = 0,
        public readonly ?string $group = null,
        public readonly int|string $columnSpan = 1,
        public readonly array $settings = [],
        public readonly array $schema = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(array $attributes): self
    {
        $type = $attributes['type'] instanceof ContentInputType
            ? $attributes['type']
            : ContentInputType::from($attributes['type']);

        return new self(
            key: $attributes['key'],
            type: $type,
            labelEn: $attributes['label_en'],
            labelAr: $attributes['label_ar'],
            helpEn: $attributes['help_en'] ?? null,
            helpAr: $attributes['help_ar'] ?? null,
            translatable: $attributes['translatable'] ?? false,
            sortOrder: $attributes['sort_order'] ?? 0,
            group: $attributes['group'] ?? null,
            columnSpan: $attributes['column_span'] ?? 1,
            settings: $attributes['settings'] ?? [],
            schema: $attributes['schema'] ?? [],
        );
    }
}
