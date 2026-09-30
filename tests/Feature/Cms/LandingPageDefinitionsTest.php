<?php

namespace Tests\Feature\Cms;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\PageContentDefinition;
use App\Support\Content\Enums\ContentInputType;
use Tests\TestCase;

/**
 * The home page definition is the contract with the landing-page frontend
 * (see the plan in docs/website-content-dashboard-guide.md) — these tests
 * pin its section keys, item keys, limits and default data.
 */
class LandingPageDefinitionsTest extends TestCase
{
    private function home(): PageContentDefinition
    {
        return app(ContentDefinitionRegistry::class)->forPage('home');
    }

    /** @return list<string> */
    private function itemKeys(string $sectionKey): array
    {
        return array_map(
            fn (ContentItemDefinition $item): string => $item->key,
            $this->home()->section($sectionKey)->orderedItems(),
        );
    }

    private function item(string $sectionKey, string $itemKey): ContentItemDefinition
    {
        foreach ($this->home()->section($sectionKey)->items as $item) {
            if ($item->key === $itemKey) {
                return $item;
            }
        }

        $this->fail("Item '{$itemKey}' is not defined on home/{$sectionKey}.");
    }

    public function test_home_has_the_five_landing_sections_in_design_order(): void
    {
        $keys = array_map(fn ($section): string => $section->sectionKey, $this->home()->orderedSections());

        $this->assertSame(['hero', 'features', 'plans', 'ai_assistant', 'on_mobile'], $keys);
    }

    public function test_each_section_defines_exactly_the_fields_the_frontend_asked_for(): void
    {
        $this->assertSame(['title', 'description', 'image', 'image_small'], $this->itemKeys('hero'));
        $this->assertSame(['title', 'description', 'features'], $this->itemKeys('features'));
        $this->assertSame(['title', 'description', 'plans'], $this->itemKeys('plans'));
        $this->assertSame(
            ['subtitle', 'title', 'description', 'image', 'supporting_images', 'logo', 'highlights'],
            $this->itemKeys('ai_assistant'),
        );
        $this->assertSame(
            ['subtitle', 'title', 'description', 'app_store_url', 'google_play_url', 'image'],
            $this->itemKeys('on_mobile'),
        );
    }

    public function test_repeater_rows_expose_the_requested_fields(): void
    {
        $rowKeys = fn (string $section, string $item): array => array_map(
            fn (ContentItemDefinition $nested): string => $nested->key,
            $this->item($section, $item)->schema,
        );

        $this->assertSame(['title', 'description', 'icon'], $rowKeys('features', 'features'));
        $this->assertSame(['title', 'icon'], $rowKeys('ai_assistant', 'highlights'));
        $this->assertSame(
            ['title', 'price', 'discount', 'billing_type', 'features', 'background_color', 'icon', 'icon_color'],
            $rowKeys('plans', 'plans'),
        );
    }

    public function test_item_counts_are_capped_to_the_design(): void
    {
        $this->assertSame(6, $this->item('features', 'features')->settings['max_items']);
        $this->assertSame(3, $this->item('ai_assistant', 'highlights')->settings['max_items']);
        $this->assertSame(6, $this->item('plans', 'plans')->settings['max_items']);
        $this->assertSame(4, $this->item('ai_assistant', 'supporting_images')->settings['max_files']);
        $this->assertSame(ContentInputType::MultiImage, $this->item('ai_assistant', 'supporting_images')->type);
    }

    public function test_hero_images_map_to_distinct_media_collections(): void
    {
        $this->assertSame(ContentInputType::Image, $this->item('hero', 'image')->type);
        $this->assertSame(ContentInputType::Image, $this->item('hero', 'image_small')->type);
        $this->assertSame(ContentInputType::Image, $this->item('ai_assistant', 'logo')->type);
    }

    public function test_default_data_seeds_six_features_three_highlights_and_three_plans_per_billing_type(): void
    {
        $data = fn (string $section): array => $this->home()->section($section)->defaultData;

        $this->assertCount(6, $data('features')['features']);
        $this->assertCount(3, $data('ai_assistant')['highlights']);

        $plans = $data('plans')['plans'];
        $this->assertCount(3, array_filter($plans, fn (array $plan): bool => $plan['billing_type'] === 'monthly'));
        $this->assertCount(3, array_filter($plans, fn (array $plan): bool => $plan['billing_type'] === 'yearly'));

        foreach ($plans as $plan) {
            $this->assertCount(4, $plan['features']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $plan['background_color']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $plan['icon_color']);
        }
    }

    public function test_billing_type_is_limited_to_monthly_and_yearly(): void
    {
        $billingType = collect($this->item('plans', 'plans')->schema)->firstWhere('key', 'billing_type');

        $this->assertSame(ContentInputType::Select, $billingType->type);
        $this->assertSame(['monthly', 'yearly'], array_keys($billingType->settings['options']));
    }

    public function test_every_default_text_has_both_english_and_arabic(): void
    {
        $missing = [];

        $walk = function (mixed $value, string $path) use (&$walk, &$missing): void {
            if (! is_array($value)) {
                return;
            }

            if (array_key_exists('en', $value) || array_key_exists('ar', $value)) {
                if (blank($value['en'] ?? null) || blank($value['ar'] ?? null)) {
                    $missing[] = $path;
                }

                return;
            }

            foreach ($value as $key => $child) {
                $walk($child, "{$path}.{$key}");
            }
        };

        foreach ($this->home()->orderedSections() as $section) {
            $walk($section->defaultData, $section->sectionKey);
        }

        $this->assertSame([], $missing, 'Default copy missing a locale: '.implode(', ', $missing));
    }
}
