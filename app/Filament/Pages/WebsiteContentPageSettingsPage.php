<?php

namespace App\Filament\Pages;

use App\Http\Controllers\SitemapController;
use App\Models\Page;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Localization\PublicLocales;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page as BasePage;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class WebsiteContentPageSettingsPage extends BasePage
{
    protected string $view = 'filament.pages.website-content-page-settings-page';

    protected static ?string $slug = 'website-content-page-settings/{pageKey}';

    protected static bool $shouldRegisterNavigation = false;

    private const TRANSLATABLE_FIELDS = ['meta_title', 'meta_description', 'public_path'];

    public string $pageKey = '';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('content.view');
    }

    public function canUpdate(): bool
    {
        return (bool) auth('admin')->user()?->hasPermissionTo('content.update');
    }

    public function mount(string $pageKey): void
    {
        if (! app(ContentDefinitionRegistry::class)->has($pageKey)) {
            abort(404);
        }

        $this->pageKey = $pageKey;
        $page = Page::query()->where('key', $pageKey)->first();

        $this->form->fill([
            'meta_title' => $this->localizedState($page, 'meta_title'),
            'meta_description' => $this->localizedState($page, 'meta_description'),
            'public_path' => $this->localizedState($page, 'public_path'),
            'canonical_url' => $page?->canonical_url ?? '',
            'is_indexable' => $page?->is_indexable ?? true,
            'include_in_sitemap' => $page?->include_in_sitemap ?? true,
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.cms.page_seo_settings_title');
    }

    public function getSubheading(): ?string
    {
        return WebsiteContentPage::pageTitle(app(ContentDefinitionRegistry::class)->forPage($this->pageKey));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->fieldComponents())->columns(1)->statePath('data');
    }

    public function save(): void
    {
        if (! $this->canUpdate()) {
            Notification::make()->title(__('admin.cms.page_seo_unauthorized'))->danger()->send();

            return;
        }

        $page = Page::query()->where('key', $this->pageKey)->first();

        if ($page === null) {
            Notification::make()->title(__('admin.cms.page_seo_missing_page'))->danger()->send();

            return;
        }

        $state = $this->form->getState();

        foreach (self::TRANSLATABLE_FIELDS as $attribute) {
            $this->applyTranslations($page, $attribute, $state[$attribute] ?? []);
        }

        $page->canonical_url = trim((string) ($state['canonical_url'] ?? '')) ?: null;
        $page->is_indexable = (bool) ($state['is_indexable'] ?? true);
        $page->include_in_sitemap = (bool) ($state['include_in_sitemap'] ?? true);
        $page->updated_by_admin_id = auth('admin')->id();
        $page->save();

        Cache::forget(SitemapController::SITEMAP_CACHE_KEY);
        activity('settings')->causedBy(auth('admin')->user())
            ->withProperties(['page_key' => $this->pageKey])->log('page_seo.updated');
        Notification::make()->title(__('admin.cms.page_seo_saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label(__('admin.access_management.save'))->icon('heroicon-o-check')
                ->action('save')->color('primary')->visible(fn (): bool => $this->canUpdate()),
        ];
    }

    /** @param array<string, mixed> $values */
    private function applyTranslations(Page $page, string $attribute, array $values): void
    {
        foreach (PublicLocales::all() as $locale) {
            $value = trim((string) ($values[$locale] ?? ''));

            if ($value === '') {
                $page->forgetTranslation($attribute, $locale);
            } else {
                $page->setTranslation($attribute, $locale, $value);
            }
        }

        if ($page->getTranslations($attribute) === []) {
            $page->forgetTranslations($attribute, asNull: true);
        }
    }

    /** @return array<string, string> */
    private function localizedState(?Page $page, string $attribute): array
    {
        $state = [];

        foreach (PublicLocales::all() as $locale) {
            $state[$locale] = (string) ($page?->getTranslation($attribute, $locale, false) ?? '');
        }

        return $state;
    }

    /** @return array<int, Component> */
    private function fieldComponents(): array
    {
        $editable = $this->canUpdate();
        $components = [];

        foreach (PublicLocales::all() as $locale) {
            $components[] = TextInput::make("meta_title.{$locale}")
                ->label(__('admin.cms.page_seo_field_meta_title').' ('.__("admin.cms.locale_{$locale}").')')
                ->maxLength(255)->disabled(! $editable)->dehydrated($editable)->columnSpanFull();
        }

        foreach (PublicLocales::all() as $locale) {
            $components[] = Textarea::make("meta_description.{$locale}")
                ->label(__('admin.cms.page_seo_field_meta_description').' ('.__("admin.cms.locale_{$locale}").')')
                ->rows(3)->maxLength(500)->disabled(! $editable)->dehydrated($editable)->columnSpanFull();
        }

        foreach (PublicLocales::all() as $locale) {
            $components[] = TextInput::make("public_path.{$locale}")
                ->label(__('admin.cms.page_seo_field_public_path').' ('.__("admin.cms.locale_{$locale}").')')
                ->helperText(__('admin.cms.page_seo_field_public_path_helper'))->maxLength(255)
                ->disabled(! $editable)->dehydrated($editable)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                ->rules([
                    'regex:/^\/\S*$/',
                    Rule::unique('pages', "public_path->{$locale}")->ignore($this->pageKey, 'key'),
                ], fn (?string $state): bool => filled($state))
                ->validationMessages([
                    'regex' => __('admin.cms.page_seo_field_public_path_format_error'),
                    'unique' => __('admin.cms.page_seo_field_public_path_taken'),
                ])->columnSpanFull();
        }

        $components[] = TextInput::make('canonical_url')
            ->label(__('admin.cms.page_seo_field_canonical_url'))
            ->helperText(__('admin.cms.page_seo_field_canonical_url_helper'))
            ->rules(['url'], fn (?string $state): bool => filled($state))->maxLength(255)
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
            ->disabled(! $editable)->dehydrated($editable)->columnSpanFull();

        $components[] = Toggle::make('is_indexable')->label(__('admin.cms.page_seo_field_is_indexable'))
            ->helperText(__('admin.cms.page_seo_field_is_indexable_helper'))
            ->disabled(! $editable)->dehydrated($editable)->columnSpanFull();
        $components[] = Toggle::make('include_in_sitemap')->label(__('admin.cms.page_seo_field_include_in_sitemap'))
            ->helperText(__('admin.cms.page_seo_field_include_in_sitemap_helper'))
            ->disabled(! $editable)->dehydrated($editable)->columnSpanFull();

        return $components;
    }
}
