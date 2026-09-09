<?php

namespace App\Filament\Pages;

use App\Enums\SettingValueType;
use App\Http\Controllers\SitemapController;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page as BasePage;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;

class RobotsSettingsPage extends BasePage
{
    protected string $view = 'filament.pages.robots-settings-page';

    protected static ?string $slug = 'sitemap-robots-settings';

    protected static bool $shouldRegisterNavigation = false;

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

    public function getTitle(): string|Htmlable
    {
        return __('admin.cms.sitemap_robots_settings_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.cms.sitemap_robots_settings_description');
    }

    public function mount(): void
    {
        $this->form->fill([
            'robots_body' => $this->storedBody() ?? SitemapController::DEFAULT_ROBOTS_BODY,
            'confirm_disallow_all' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->fieldComponents())->columns(1)->statePath('data');
    }

    public function save(): void
    {
        if (! $this->canUpdate()) {
            Notification::make()->title(__('admin.cms.sitemap_robots_unauthorized'))->danger()->send();

            return;
        }

        $state = $this->form->getState();
        $body = trim((string) ($state['robots_body'] ?? '')) ?: SitemapController::DEFAULT_ROBOTS_BODY;

        if ($this->blocksAllCrawlers($body) && ! (bool) ($state['confirm_disallow_all'] ?? false)) {
            Notification::make()->title(__('admin.cms.sitemap_robots_danger_warning'))->danger()->send();

            return;
        }

        Setting::query()->updateOrCreate(
            ['key' => SitemapController::ROBOTS_SETTING_KEY],
            ['value' => $body, 'value_type' => SettingValueType::Text, 'group' => 'seo', 'is_public' => false],
        );

        Cache::forget(SitemapController::SITEMAP_CACHE_KEY);
        activity('settings')->causedBy(auth('admin')->user())->log('robots_txt.updated');
        $this->form->fill(['robots_body' => $body, 'confirm_disallow_all' => false]);
        Notification::make()->title(__('admin.cms.sitemap_robots_saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label(__('admin.access_management.save'))->icon('heroicon-o-check')
                ->action('save')->color('primary')->visible(fn (): bool => $this->canUpdate()),
        ];
    }

    public function fullRobotsTxt(string $body): string
    {
        $body = trim($body) !== '' ? trim($body) : SitemapController::DEFAULT_ROBOTS_BODY;

        return $body."\n\nSitemap: ".SitemapController::sitemapUrl();
    }

    public function blocksAllCrawlers(string $body): bool
    {
        return (bool) preg_match('/User-agent:\s*\*\s*[\r\n]+\s*Disallow:\s*\/\s*($|[\r\n])/i', trim($body)."\n");
    }

    private function storedBody(): ?string
    {
        $value = Setting::query()->where('key', SitemapController::ROBOTS_SETTING_KEY)->value('value');

        return filled($value) ? (string) $value : null;
    }

    /** @return array<int, Component> */
    private function fieldComponents(): array
    {
        $editable = $this->canUpdate();

        return [
            Textarea::make('robots_body')->label(__('admin.cms.sitemap_robots_field_body'))
                ->helperText(__('admin.cms.sitemap_robots_field_body_helper'))->rows(8)->required()
                ->live(onBlur: true)->disabled(! $editable)->dehydrated($editable)->columnSpanFull(),
            Toggle::make('confirm_disallow_all')
                ->label(__('admin.cms.sitemap_robots_field_confirm_disallow_all'))
                ->visible(fn (Get $get): bool => $this->blocksAllCrawlers((string) ($get('robots_body') ?? '')))
                ->live()->dehydrated($editable)->columnSpanFull(),
            TextEntry::make('robots_preview')->label(__('admin.cms.sitemap_robots_preview_label'))
                ->state(fn (Get $get): string => $this->fullRobotsTxt((string) ($get('robots_body') ?? '')))
                ->columnSpanFull(),
        ];
    }
}
