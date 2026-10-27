<?php

namespace App\Support\Content\Forms;

use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Enums\ContentInputType;
use Illuminate\Validation\ValidationException;

class EnglishContentData
{
    public const ROW_INDEX = '__original_content_row';

    /**
     * @param  list<ContentItemDefinition>  $items
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forForm(array $items, array $data): array
    {
        foreach ($items as $item) {
            if ($item->type !== ContentInputType::Repeater) {
                continue;
            }

            foreach ($data[$item->key] ?? [] as $index => $row) {
                $data[$item->key][$index] = [
                    ...self::forForm($item->schema, $row),
                    self::ROW_INDEX => $index,
                ];
            }
        }

        return $data;
    }

    /**
     * @param  list<ContentItemDefinition>  $items
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public static function merge(array $items, array $stored, array $submitted): array
    {
        foreach ($items as $item) {
            if (! array_key_exists($item->key, $submitted)) {
                continue;
            }

            $value = $submitted[$item->key];
            $previous = $stored[$item->key] ?? [];

            if ($item->type === ContentInputType::Repeater) {
                $value = self::mergeRows($item, is_array($previous) ? $previous : [], $value ?? []);
            } elseif ($item->type === ContentInputType::Cta) {
                $previous = is_array($previous) ? $previous : [];
                $value = [
                    ...$previous,
                    'text' => [...($previous['text'] ?? []), 'en' => $value['text']['en'] ?? null],
                    'url' => $value['url'] ?? null,
                ];
            } elseif ($item->translatable) {
                $value = [...(is_array($previous) ? $previous : []), 'en' => $value['en'] ?? null];
            }

            $stored[$item->key] = $value;
        }

        return $stored;
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  list<array<string, mixed>>  $submitted
     * @return list<array<string, mixed>>
     */
    private static function mergeRows(ContentItemDefinition $item, array $stored, array $submitted): array
    {
        $rows = [];
        $used = [];

        foreach ($submitted as $row) {
            $index = $row[self::ROW_INDEX] ?? null;

            if ($index !== null && (! is_int($index) || ! array_key_exists($index, $stored) || isset($used[$index]))) {
                throw ValidationException::withMessages(['data.data.'.$item->key => 'This content has changed. Reload the page before saving.']);
            }

            $rows[] = self::merge($item->schema, $index === null ? [] : $stored[$index], $row);
            if ($index !== null) {
                $used[$index] = true;
            }
        }

        return $rows;
    }
}
