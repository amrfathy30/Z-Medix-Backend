<?php

namespace App\Filament\Pages;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\PageContentDefinition;
use Filament\Pages\Page as BasePage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class WebsiteContentPage extends BasePage
{
    protected string $view = 'filament.pages.website-content-page';

    protected static ?string $slug = 'website-content';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.website_content_navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedGlobeAlt;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_content');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('content.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.cms.website_content_title');
    }

    /**
     * @return array<int, array{key: string, title: string, type: string, actionLabel: string, url: ?string, disabled: bool}>
     */
    public function getCards(): array
    {
        $cards = array_map(
            fn (PageContentDefinition $definition): array => $this->sectionCard($definition),
            app(ContentDefinitionRegistry::class)->allPages(),
        );

        $cards[] = $this->footerCard();
        $cards[] = $this->crawlerFilesCard();

        return $cards;
    }

    public static function pageTitle(PageContentDefinition $definition): string
    {
        $key = 'admin.cms.website_content_card_'.str_replace('-', '_', $definition->key);
        $translated = __($key);

        return $translated !== $key
            ? $translated
            : (app()->getLocale() === 'ar' ? $definition->labelAr : $definition->labelEn);
    }

    private function sectionCard(PageContentDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'title' => self::pageTitle($definition),
            'type' => __('admin.cms.website_content_type_sections'),
            'actionLabel' => __('admin.cms.website_content_action_manage_sections'),
            'url' => WebsiteContentSectionsPage::getUrl(['pageKey' => $definition->key]),
            'seoUrl' => WebsiteContentPageSettingsPage::getUrl(['pageKey' => $definition->key]),
            'disabled' => false,
        ];
    }

    /** @return array{key: string, title: string, type: string, actionLabel: string, url: ?string, disabled: bool} */
    private function footerCard(): array
    {
        return [
            'key' => 'footer',
            'title' => __('admin.cms.website_content_card_footer'),
            'type' => __('admin.cms.website_content_type_global'),
            'actionLabel' => __('admin.cms.website_content_action_edit'),
            'url' => SettingsPage::getUrl(),
            'seoUrl' => null,
            'disabled' => false,
        ];
    }

    private function crawlerFilesCard(): array
    {
        return [
            'key' => 'crawler-files',
            'title' => __('admin.cms.website_content_card_crawler_files'),
            'type' => __('admin.cms.website_content_type_global'),
            'actionLabel' => __('admin.cms.website_content_action_edit'),
            'url' => RobotsSettingsPage::getUrl(),
            'seoUrl' => null,
            'disabled' => false,
        ];
    }
}
