<?php

namespace App\Support\Content\Media;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Enums\ContentInputType;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;

/**
 * Turns the file paths stored by Image items nested inside repeater rows
 * (see ContentInputFactory::makeNestedImage()) into public URLs. Top-level
 * Image items are Spatie media and never appear in `data`, so only repeater
 * schemas are walked. Sections without a definition pass through untouched.
 */
class ContentMediaUrlResolver
{
    public function __construct(private readonly ContentDefinitionRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, ?string $pageKey, string $sectionKey): array
    {
        if ($pageKey === null || ! $this->registry->hasSection($pageKey, $sectionKey)) {
            return $data;
        }

        return $this->resolveRepeaters($data, $this->registry->forSection($pageKey, $sectionKey)->items);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<ContentItemDefinition>  $items
     * @return array<string, mixed>
     */
    private function resolveRepeaters(array $data, array $items): array
    {
        foreach ($items as $item) {
            if ($item->type !== ContentInputType::Repeater || ! is_array($data[$item->key] ?? null)) {
                continue;
            }

            $data[$item->key] = array_map(
                fn (mixed $row): mixed => is_array($row) ? $this->resolveRow($row, $item->schema) : $row,
                $data[$item->key],
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<ContentItemDefinition>  $schema
     * @return array<string, mixed>
     */
    private function resolveRow(array $row, array $schema): array
    {
        foreach ($schema as $nested) {
            if ($nested->type === ContentInputType::Image && array_key_exists($nested->key, $row)) {
                $row[$nested->key] = $this->url($row[$nested->key]);
            }
        }

        return $this->resolveRepeaters($row, $schema);
    }

    private function url(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return PublicMediaUrl::make(Storage::disk('filament_public')->url($path));
    }
}
