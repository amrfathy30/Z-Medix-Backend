<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentSectionDefinition;
use App\Support\Content\Definitions\PageContentDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds the page_sections system for every page defined in
 * WebsiteContentDefinitions (home, about-us, services, privacy-policy,
 * terms-and-conditions, contact-us) — the single source of truth for which
 * Website Content pages/sections exist.
 *
 * Behavior is intentionally seed-once for content: a section's `data` is
 * only written when the row is first created. On every later run, only
 * structural metadata (sort_order, label) is refreshed — an admin's edited
 * content is never touched.
 *
 * Since Phase 3H, pages/sections are created and matched purely by
 * `key`/`page_id` + `section_key` — the legacy page_key/type/title/
 * subtitle/body/is_public columns no longer exist.
 */
class PageSectionSeeder extends Seeder
{
    /** @var list<string> */
    private const DESIGNED_PAGE_KEYS = [
        'home', 'about-us', 'services',
        'privacy-policy', 'terms-and-conditions', 'contact-us',
    ];

    public function run(): void
    {
        $registry = app(ContentDefinitionRegistry::class);

        foreach (self::DESIGNED_PAGE_KEYS as $pageKey) {
            $this->seedPage($registry, $pageKey);
        }
    }

    private function seedPage(ContentDefinitionRegistry $registry, string $pageKey): void
    {
        $definition = $registry->forPage($pageKey);
        $page = $this->resolvePage($pageKey, $definition);

        foreach ($definition->orderedSections() as $section) {
            $this->seedSection($page, $section);
        }
    }

    /** Looked up by `key`; creates the Page row itself if it doesn't exist yet. */
    private function resolvePage(string $pageKey, PageContentDefinition $definition): Page
    {
        $page = Page::query()->where('key', $pageKey)->first();

        if ($page !== null) {
            return $page;
        }

        return Page::create([
            'key' => $pageKey,
            'label' => $definition->labelEn,
            'status' => ContentStatus::Published,
            'sort_order' => 0,
        ]);
    }

    private function seedSection(Page $page, ContentSectionDefinition $section): void
    {
        $pageSection = PageSection::firstOrCreate(
            ['page_id' => $page->id, 'section_key' => $section->sectionKey],
            [
                'label' => $section->labelEn,
                'data' => $section->defaultData,
                'sort_order' => $section->sortOrder,
                'status' => ContentStatus::Published,
            ]
        );

        // Structural metadata only — never touch data/status on a section
        // that already existed before this run.
        $pageSection->forceFill([
            'label' => $section->labelEn,
            'sort_order' => $section->sortOrder,
        ])->saveQuietly();
    }
}
