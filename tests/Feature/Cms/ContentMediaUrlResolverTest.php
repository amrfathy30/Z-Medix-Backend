<?php

namespace Tests\Feature\Cms;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\ContentSectionDefinition;
use App\Support\Content\Definitions\PageContentDefinition;
use App\Support\Content\Enums\ContentInputType;
use App\Support\Content\Media\ContentMediaUrlResolver;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentMediaUrlResolverTest extends TestCase
{
    private function resolver(): ContentMediaUrlResolver
    {
        $icon = ContentItemDefinition::make([
            'key' => 'icon', 'type' => ContentInputType::Image, 'label_en' => 'Icon', 'label_ar' => 'الأيقونة',
        ]);
        $title = ContentItemDefinition::make([
            'key' => 'title', 'type' => ContentInputType::ShortText, 'label_en' => 'Title', 'label_ar' => 'العنوان', 'translatable' => true,
        ]);
        $inner = ContentItemDefinition::make([
            'key' => 'badges', 'type' => ContentInputType::Repeater, 'label_en' => 'Badges', 'label_ar' => 'الشارات', 'schema' => [$icon],
        ]);
        $outer = ContentItemDefinition::make([
            'key' => 'cards', 'type' => ContentInputType::Repeater, 'label_en' => 'Cards', 'label_ar' => 'البطاقات', 'schema' => [$title, $icon, $inner],
        ]);

        $registry = (new ContentDefinitionRegistry)->register(PageContentDefinition::make('demo', 'Demo', 'تجريبي', [
            ContentSectionDefinition::make(['section_key' => 'grid', 'label_en' => 'Grid', 'label_ar' => 'شبكة', 'items' => [$outer]]),
        ]));

        return new ContentMediaUrlResolver($registry);
    }

    private function publicUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').Storage::disk('filament_public')->url($path);
    }

    public function test_stored_paths_in_repeater_rows_become_absolute_public_urls(): void
    {
        $data = ['cards' => [
            ['title' => 'A', 'icon' => 'content/a.png'],
            ['title' => 'B', 'icon' => 'content/b.svg'],
        ]];

        $resolved = $this->resolver()->resolve($data, 'demo', 'grid');

        $this->assertSame($this->publicUrl('content/a.png'), $resolved['cards'][0]['icon']);
        $this->assertSame($this->publicUrl('content/b.svg'), $resolved['cards'][1]['icon']);
        $this->assertSame('A', $resolved['cards'][0]['title']);
    }

    public function test_nested_repeaters_are_resolved_too(): void
    {
        $data = ['cards' => [['icon' => null, 'badges' => [['icon' => 'content/deep.png']]]]];

        $resolved = $this->resolver()->resolve($data, 'demo', 'grid');

        $this->assertSame($this->publicUrl('content/deep.png'), $resolved['cards'][0]['badges'][0]['icon']);
    }

    public function test_empty_or_missing_icons_resolve_to_null_and_absent_keys_stay_absent(): void
    {
        $resolved = $this->resolver()->resolve(['cards' => [['icon' => ''], ['icon' => null], ['title' => 'No icon key']]], 'demo', 'grid');

        $this->assertNull($resolved['cards'][0]['icon']);
        $this->assertNull($resolved['cards'][1]['icon']);
        $this->assertArrayNotHasKey('icon', $resolved['cards'][2]);
    }

    public function test_data_of_sections_without_a_definition_passes_through_unchanged(): void
    {
        $data = ['cards' => [['icon' => 'content/a.png']]];

        $this->assertSame($data, $this->resolver()->resolve($data, 'demo', 'unknown-section'));
        $this->assertSame($data, $this->resolver()->resolve($data, 'unknown-page', 'grid'));
        $this->assertSame($data, $this->resolver()->resolve($data, null, 'grid'));
    }
}
