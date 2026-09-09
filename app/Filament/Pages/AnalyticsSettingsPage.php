<?php

namespace App\Filament\Pages;

use App\Models\GoogleAnalyticsSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Google Analytics (GA4) integration settings.
 *
 * Backed by the dedicated google_analytics_settings table (one row), not
 * generic `settings` rows — keeps these fields off the Website Settings
 * page's auto-generated group tabs and keeps the public analytics endpoint
 * independent of the public settings endpoint.
 *
 * @property-read Schema $form
 */
class AnalyticsSettingsPage extends Page
{
    protected string $view = 'filament.pages.analytics-settings-page';

    protected static ?string $slug = 'analytics-settings';

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<int, string> */
    private const FIELDS = ['enabled', 'measurement_id', 'api_secret', 'property_id', 'service_account_json'];

    /** @var array<int, string> */
    private const SECRET_FIELDS = ['api_secret', 'service_account_json'];

    public static function getNavigationLabel(): string
    {
        return __('admin.analytics_settings.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedChartBar;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_marketing');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('settings.integrations.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.analytics_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.analytics_settings.description');
    }

    public function mount(): void
    {
        $this->form->fill($this->hydratableState());
    }

    /** @return array<string, mixed> */
    private function hydratableState(): array
    {
        $state = GoogleAnalyticsSetting::current()->only(self::FIELDS);

        foreach (self::SECRET_FIELDS as $field) {
            $state[$field] = null;
        }

        return $state;
    }

    private function secretIndicator(string $field): string
    {
        $value = (string) GoogleAnalyticsSetting::current()->getAttribute($field);

        return blank($value)
            ? __('admin.analytics_settings.secret_not_configured')
            : __('admin.analytics_settings.secret_configured', ['ending' => Str::substr($value, -4)]);
    }

    public function canUpdate(): bool
    {
        return (bool) auth('admin')->user()?->hasPermissionTo('settings.integrations.update');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->fieldComponents())
            ->columns(2)
            ->statePath('data');
    }

    public function save(): void
    {
        if (! $this->canUpdate()) {
            Notification::make()
                ->title(__('admin.analytics_settings.unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $state = $this->form->getState();
        $admin = auth('admin')->user();
        $setting = GoogleAnalyticsSetting::current();

        $before = $setting->only(self::FIELDS);
        $setting->fill($state);
        $changedKeys = array_keys($setting->getDirty());
        $setting->save();

        foreach ($changedKeys as $key) {
            $isSecret = in_array($key, self::SECRET_FIELDS, strict: true);

            activity('settings')
                ->causedBy($admin)
                ->withProperties([
                    'key' => $key,
                    'old_value' => $isSecret ? null : ($before[$key] ?? null),
                    'new_value' => $isSecret ? null : $setting->getAttribute($key),
                    'secret' => $isSecret,
                ])
                ->log($isSecret ? 'analytics_settings.secret_updated' : 'analytics_settings.updated');
        }

        $setting->refresh();
        $this->form->fill($this->hydratableState());

        Notification::make()
            ->title(__('admin.analytics_settings.saved'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('admin.access_management.save'))
                ->icon('heroicon-o-check')
                ->action('save')
                ->color('primary')
                ->visible(fn (): bool => $this->canUpdate()),
        ];
    }

    /** @return array<int, Component> */
    private function fieldComponents(): array
    {
        $editable = $this->canUpdate();

        return [
            Toggle::make('enabled')
                ->label(__('admin.analytics_settings.fields.ga_enabled.label'))
                ->helperText(__('admin.analytics_settings.fields.ga_enabled.helper'))
                ->disabled(! $editable)
                ->dehydrated($editable)
                ->columnSpanFull(),

            TextInput::make('measurement_id')
                ->label(__('admin.analytics_settings.fields.ga_measurement_id.label'))
                ->helperText(__('admin.analytics_settings.fields.ga_measurement_id.helper'))
                ->placeholder('G-XXXXXXXXXX')
                ->autocomplete('off')
                ->disabled(! $editable)
                ->dehydrated($editable)
                ->maxLength(255)
                ->rules(['nullable', 'regex:/^G-[A-Z0-9]+$/'])
                ->validationMessages(['regex' => __('admin.analytics_settings.fields.ga_measurement_id.format_error')]),

            Placeholder::make('api_secret_status')
                ->label(__('admin.analytics_settings.fields.ga_api_secret.current_label'))
                ->content(fn (): string => $this->secretIndicator('api_secret')),

            TextInput::make('api_secret')
                ->label(__('admin.analytics_settings.fields.ga_api_secret.replace_label'))
                ->helperText(__('admin.analytics_settings.fields.ga_api_secret.helper'))
                ->password()
                ->autocomplete('new-password')
                ->disabled(! $editable)
                ->dehydrated(fn (?string $state): bool => $editable && filled($state))
                ->maxLength(255),

            TextInput::make('property_id')
                ->label(__('admin.analytics_settings.fields.ga_property_id.label'))
                ->helperText(__('admin.analytics_settings.fields.ga_property_id.helper'))
                ->placeholder('123456789')
                ->autocomplete('off')
                ->disabled(! $editable)
                ->dehydrated($editable)
                ->maxLength(255)
                ->rules(['nullable', 'regex:/^\d+$/'])
                ->validationMessages(['regex' => __('admin.analytics_settings.fields.ga_property_id.format_error')]),

            Placeholder::make('service_account_json_status')
                ->label(__('admin.analytics_settings.fields.ga_service_account_json.current_label'))
                ->content(fn (): string => $this->secretIndicator('service_account_json'))
                ->columnSpanFull(),

            Textarea::make('service_account_json')
                ->label(__('admin.analytics_settings.fields.ga_service_account_json.replace_label'))
                ->helperText(__('admin.analytics_settings.fields.ga_service_account_json.helper'))
                ->rows(6)
                ->rules(['nullable', 'json'])
                ->disabled(! $editable)
                ->dehydrated(fn (?string $state): bool => $editable && filled($state))
                ->columnSpanFull(),
        ];
    }
}
