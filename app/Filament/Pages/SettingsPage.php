<?php

namespace App\Filament\Pages;

use App\Enums\SettingValueType;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * @property-read Schema $form
 */
class SettingsPage extends Page
{
    protected string $view = 'filament.pages.settings-page';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Preferred display order for group tabs. */
    private const GROUP_ORDER = ['general', 'contact', 'footer', 'social'];

    /**
     * Groups no longer rendered as tabs here — their Setting rows remain in
     * the database untouched. Home page content is now managed exclusively
     * through Website Content → Home Page → page_sections.
     */
    private const HIDDEN_GROUPS = ['home'];

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.settings_navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedCog8Tooth;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_settings');
    }

    public static function getSettingLabel(string $key): string
    {
        $transKey = "admin.cms.settings_keys.{$key}";
        $translated = __($transKey);

        return $translated !== $transKey ? $translated : Str::headline($key);
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('settings.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.cms.settings_title');
    }

    public function mount(): void
    {
        $this->form->fill(
            $this->getSettings()
                ->reject(fn (Setting $setting): bool => in_array($setting->group, self::HIDDEN_GROUPS, true))
                ->mapWithKeys(fn (Setting $setting): array => ["setting_{$setting->id}" => $this->castValueForForm($setting)])
                ->all()
        );
    }

    /** @return Collection<int, Setting> */
    public function getSettings(): Collection
    {
        return Setting::where('is_public', true)
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }

    /** @return array<string, Collection<int, Setting>> */
    public function getGroupedSettings(): array
    {
        $grouped = $this->getSettings()
            ->reject(fn (Setting $setting): bool => in_array($setting->group, self::HIDDEN_GROUPS, true))
            ->groupBy('group')
            ->all();

        $ordered = [];
        foreach (self::GROUP_ORDER as $group) {
            if (isset($grouped[$group])) {
                $ordered[$group] = $grouped[$group];
            }
        }

        foreach ($grouped as $group => $settings) {
            if (! isset($ordered[$group])) {
                $ordered[$group] = $settings;
            }
        }

        return $ordered;
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach ($this->getGroupedSettings() as $group => $settings) {
            $ordered = $settings
                ->sortBy(fn (Setting $setting): int => $setting->value_type === SettingValueType::Image ? 0 : 1)
                ->values();

            $tabs[] = Tab::make($this->getGroupLabel($group))
                ->schema($ordered->map(fn (Setting $setting): Component => $this->makeField($setting))->all())
                ->columns(2);
        }

        return $schema
            ->components([
                Tabs::make('settingsGroups')
                    ->tabs($tabs)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! auth('admin')->user()?->hasPermissionTo('settings.update')) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $state = $this->form->getState();

        foreach ($this->getSettings() as $setting) {
            $key = "setting_{$setting->id}";

            if (! array_key_exists($key, $state)) {
                continue;
            }

            $value = $state[$key];

            if ($setting->value_type === SettingValueType::Boolean) {
                $value = $value ? '1' : '0';
            }

            $previous = $setting->value;

            if ((string) $previous === (string) $value) {
                continue;
            }

            $setting->update(['value' => (string) $value]);

            activity('settings')
                ->causedBy(auth('admin')->user())
                ->withProperties([
                    'key' => $setting->key,
                    'old_value' => $previous,
                    'new_value' => (string) $value,
                ])
                ->log('website_settings.updated');
        }

        Notification::make()
            ->title(__('admin.cms.settings_saved'))
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
                ->visible(fn (): bool => (bool) auth('admin')->user()?->hasPermissionTo('settings.update')),
        ];
    }

    private function getGroupLabel(string $group): string
    {
        return match ($group) {
            'general' => __('admin.cms.settings_group_general'),
            'home' => __('admin.cms.settings_group_home'),
            'contact' => __('admin.cms.settings_group_contact'),
            'footer' => __('admin.cms.settings_group_footer'),
            'social' => __('admin.cms.settings_group_social'),
            default => ucfirst($group),
        };
    }

    private function castValueForForm(Setting $setting): mixed
    {
        if ($setting->value_type === SettingValueType::Boolean) {
            return in_array($setting->value, ['1', 'true'], true);
        }

        return $setting->value;
    }

    private function makeField(Setting $setting): Component
    {
        $statePath = "setting_{$setting->id}";
        $label = static::getSettingLabel($setting->key);
        $helperText = $setting->description;

        return match ($setting->value_type) {
            SettingValueType::Boolean => Toggle::make($statePath)
                ->label($label)
                ->helperText($helperText),

            SettingValueType::Text, SettingValueType::Json => Textarea::make($statePath)
                ->label($label)
                ->helperText($helperText)
                ->rows(4)
                ->columnSpanFull(),

            SettingValueType::Integer => TextInput::make($statePath)
                ->label($label)
                ->helperText($helperText)
                ->numeric(),

            SettingValueType::Decimal => TextInput::make($statePath)
                ->label($label)
                ->helperText($helperText)
                ->numeric()
                ->step('0.01'),

            SettingValueType::Image => FileUpload::make($statePath)
                ->label($label)
                ->helperText($helperText)
                ->image()
                ->disk('filament_public')
                ->visibility('public')
                ->directory('settings')
                ->acceptedFileTypes(config('media.image_mime_types', []))
                ->maxSize(2 * 1024)
                ->imagePreviewHeight('80')
                ->columnSpanFull(),

            default => $this->makeStringField($statePath, $label, $helperText, $setting->key),
        };
    }

    private function makeStringField(string $statePath, string $label, ?string $helperText, string $key): TextInput
    {
        $field = TextInput::make($statePath)
            ->label($label)
            ->helperText($helperText);

        if (str_contains($key, 'email')) {
            return $field->email();
        }

        if (str_starts_with($key, 'social_')) {
            return $field->url();
        }

        return $field;
    }
}
