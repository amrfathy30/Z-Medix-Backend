<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Models\PageSection;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class WebsiteContentSectionsPage extends Page
{
    protected string $view = 'filament.pages.website-content-sections-page';

    protected static ?string $slug = 'website-content-sections/{pageKey}';

    protected static bool $shouldRegisterNavigation = false;

    public string $pageKey = '';

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('content.view');
    }

    public function mount(string $pageKey): void
    {
        if (! app(ContentDefinitionRegistry::class)->has($pageKey)) {
            abort(404);
        }

        $this->pageKey = $pageKey;
    }

    public function getTitle(): string|Htmlable
    {
        return WebsiteContentPage::pageTitle(
            app(ContentDefinitionRegistry::class)->forPage($this->pageKey)
        );
    }

    /** @return Collection<int, PageSection> */
    public function getSections(): Collection
    {
        $definition = app(ContentDefinitionRegistry::class)->forPage($this->pageKey);
        $definedKeys = array_map(fn ($section) => $section->sectionKey, $definition->orderedSections());

        return PageSection::forPageKey($this->pageKey)
            ->whereIn('section_key', $definedKeys)
            ->ordered()
            ->get();
    }

    public function getSectionTitle(PageSection $section): string
    {
        $locale = app()->getLocale();
        $dataTitle = $section->data['title'] ?? null;
        $title = is_array($dataTitle) ? ($dataTitle[$locale] ?? $dataTitle['en'] ?? null) : null;

        return filled($title) ? $title : ($section->label ?: Str::headline($section->section_key));
    }

    public function getEditUrl(PageSection $section): string
    {
        return EditPageSection::getUrl(['record' => $section]);
    }
}
