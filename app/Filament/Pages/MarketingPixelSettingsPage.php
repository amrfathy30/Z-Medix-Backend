<?php

namespace App\Filament\Pages;

use App\Enums\Marketing\MarketingPixelPlatform;
use App\Models\MarketingPixel;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The singleton configuration page for the Meta, X and TikTok tracking
 * pixels, plus Meta's Conversions API settings.
 *
 * @property-read Schema $form
 */
class MarketingPixelSettingsPage extends Page
{
    protected string $view = 'filament.pages.marketing-pixel-settings-page';

    protected static ?string $slug = 'marketing-pixel-settings';

    protected static ?int $navigationSort = 21;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.marketing_pixels.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedMegaphone;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_marketing');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('marketing.pixels.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.marketing_pixels.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.marketing_pixels.description');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentFormState());
    }

    public function canUpdate(): bool
    {
        return (bool) auth('admin')->user()?->hasPermissionTo('marketing.pixels.update');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('marketingPixelTabs')
                    ->tabs([
                        $this->metaTab(),
                        $this->platformTab(MarketingPixelPlatform::X),
                        $this->platformTab(MarketingPixelPlatform::TikTok),
                    ])
                    ->persistTabInQueryString()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! $this->canUpdate()) {
            Notification::make()
                ->title(__('admin.marketing_pixels.unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $state = $this->form->getState();
        $changes = [];

        DB::transaction(function () use ($state, &$changes): void {
            $meta = is_array($state['meta'] ?? null) ? $state['meta'] : [];
            $metaAttributes = [
                'pixel_id' => $this->normalizeNullableString($meta['pixel_id'] ?? null),
                'is_public' => (bool) ($meta['is_public'] ?? false),
                'meta_capi_enabled' => (bool) ($meta['meta_capi_enabled'] ?? false),
                'meta_capi_test_mode' => (bool) ($meta['meta_capi_test_mode'] ?? false),
                'meta_capi_test_event_code' => $this->normalizeNullableString($meta['meta_capi_test_event_code'] ?? null),
            ];

            if (filled($meta['meta_capi_access_token'] ?? null)) {
                $accessToken = trim((string) $meta['meta_capi_access_token']);
                $metaAttributes['meta_capi_access_token'] = $accessToken;
                $metaAttributes['meta_capi_access_token_last4'] = Str::substr($accessToken, -4);
            }

            $this->updatePixel(
                $this->pixel(MarketingPixelPlatform::Facebook),
                $metaAttributes,
                $changes,
            );

            foreach ([MarketingPixelPlatform::X, MarketingPixelPlatform::TikTok] as $platform) {
                $submitted = is_array($state[$platform->value] ?? null) ? $state[$platform->value] : [];

                $this->updatePixel(
                    $this->pixel($platform),
                    [
                        'pixel_id' => $this->normalizeNullableString($submitted['pixel_id'] ?? null),
                        'is_public' => (bool) ($submitted['is_public'] ?? false),
                    ],
                    $changes,
                );
            }
        });

        $admin = auth('admin')->user();

        foreach ($changes as $change) {
            activity('settings')
                ->causedBy($admin)
                ->withProperties($change)
                ->log('marketing_pixels.updated');
        }

        $this->form->fill($this->currentFormState());

        Notification::make()
            ->title(__('admin.marketing_pixels.saved'))
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

    private function metaTab(): Tab
    {
        $editable = $this->canUpdate();

        return Tab::make(__('admin.marketing_pixels.tabs.meta'))
            ->schema([
                Section::make(__('admin.marketing_pixels.sections.meta_pixel'))
                    ->schema([
                        $this->pixelIdInput('meta', MarketingPixelPlatform::Facebook),
                        $this->enabledToggle('meta'),
                    ]),

                Section::make(__('admin.marketing_pixels.sections.conversions_api'))
                    ->schema([
                        Toggle::make('meta.meta_capi_enabled')
                            ->label(__('admin.marketing_pixels.fields.meta_capi_enabled'))
                            ->live()
                            ->disabled(! $editable)
                            ->dehydrated($editable),

                        TextEntry::make('meta.meta_capi_current_access_token')
                            ->label(__('admin.marketing_pixels.fields.meta_capi_current_access_token'))
                            ->state(fn (): string => $this->currentAccessTokenIndicator())
                            ->helperText(__('admin.marketing_pixels.helpers.meta_capi_current_access_token')),

                        Textarea::make('meta.meta_capi_access_token')
                            ->label(__('admin.marketing_pixels.fields.meta_capi_replace_access_token'))
                            ->helperText(__('admin.marketing_pixels.helpers.meta_capi_replace_access_token'))
                            ->rows(3)
                            ->autocomplete('new-password')
                            ->disabled(! $editable)
                            ->dehydrated(fn (?string $state): bool => $editable && filled($state))
                            ->required(fn (Get $get): bool => (bool) $get('meta.meta_capi_enabled')
                                && ! $this->hasStoredMetaAccessToken())
                            ->maxLength(4096)
                            ->rules(['nullable', 'string', 'max:4096']),
                    ]),

                Section::make(__('admin.marketing_pixels.sections.testing'))
                    ->schema([
                        Toggle::make('meta.meta_capi_test_mode')
                            ->label(__('admin.marketing_pixels.fields.meta_capi_test_mode'))
                            ->live()
                            ->disabled(! $editable)
                            ->dehydrated($editable),

                        TextInput::make('meta.meta_capi_test_event_code')
                            ->label(__('admin.marketing_pixels.fields.meta_capi_test_event_code'))
                            ->visible(fn (Get $get): bool => (bool) $get('meta.meta_capi_test_mode'))
                            ->required(fn (Get $get): bool => (bool) $get('meta.meta_capi_test_mode'))
                            ->disabled(! $editable)
                            ->dehydrated($editable)
                            ->dehydratedWhenHidden()
                            ->maxLength(255)
                            ->rules(['nullable', 'string', 'max:255']),
                    ]),
            ]);
    }

    private function platformTab(MarketingPixelPlatform $platform): Tab
    {
        return Tab::make(__("admin.marketing_pixels.platforms.{$platform->value}"))
            ->schema([
                Section::make(__("admin.marketing_pixels.platforms.{$platform->value}"))
                    ->description(__("admin.marketing_pixels.helpers.{$platform->value}"))
                    ->schema([
                        $this->pixelIdInput($platform->value, $platform),
                        $this->enabledToggle($platform->value),
                    ]),
            ]);
    }

    private function pixelIdInput(string $stateGroup, MarketingPixelPlatform $platform): TextInput
    {
        $editable = $this->canUpdate();

        return TextInput::make("{$stateGroup}.pixel_id")
            ->label(__('admin.marketing_pixels.field_pixel_id'))
            ->placeholder($platform->placeholder())
            ->helperText(__("admin.marketing_pixels.formats.{$platform->value}"))
            ->maxLength($platform->maxLength())
            ->disabled(! $editable)
            ->dehydrated($editable)
            ->rules([
                'nullable',
                'string',
                'max:'.$platform->maxLength(),
                $this->formatRule($platform),
            ]);
    }

    private function enabledToggle(string $stateGroup): Toggle
    {
        $editable = $this->canUpdate();

        return Toggle::make("{$stateGroup}.is_public")
            ->label(__('admin.marketing_pixels.field_is_public'))
            ->helperText(__('admin.marketing_pixels.field_is_public_helper'))
            ->disabled(! $editable)
            ->dehydrated($editable);
    }

    /** @return array<string, mixed> */
    private function currentFormState(): array
    {
        $meta = $this->pixel(MarketingPixelPlatform::Facebook);
        $x = $this->pixel(MarketingPixelPlatform::X);
        $tikTok = $this->pixel(MarketingPixelPlatform::TikTok);

        return [
            'meta' => [
                'pixel_id' => $meta->pixel_id,
                'is_public' => $meta->is_public,
                'meta_capi_enabled' => $meta->meta_capi_enabled,
                'meta_capi_access_token' => null,
                'meta_capi_test_mode' => $meta->meta_capi_test_mode,
                'meta_capi_test_event_code' => $meta->meta_capi_test_event_code,
            ],
            'x' => [
                'pixel_id' => $x->pixel_id,
                'is_public' => $x->is_public,
            ],
            'tiktok' => [
                'pixel_id' => $tikTok->pixel_id,
                'is_public' => $tikTok->is_public,
            ],
        ];
    }

    private function pixel(MarketingPixelPlatform $platform): MarketingPixel
    {
        return MarketingPixel::query()->where('platform', $platform)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function updatePixel(MarketingPixel $pixel, array $attributes, array &$changes): void
    {
        $oldPixelId = $pixel->pixel_id;
        $oldIsPublic = $pixel->is_public;

        $pixel->fill($attributes);
        $changedSettings = array_keys($pixel->getDirty());

        if ($changedSettings === []) {
            return;
        }

        $pixel->save();

        $changes[] = [
            'platform' => $pixel->platform->value,
            'old_pixel_id' => $oldPixelId,
            'new_pixel_id' => $pixel->pixel_id,
            'old_is_public' => $oldIsPublic,
            'new_is_public' => $pixel->is_public,
            'changed_settings' => array_values(array_unique(array_map(
                fn (string $key): string => in_array($key, [
                    'meta_capi_access_token',
                    'meta_capi_access_token_last4',
                ], true) ? 'meta_capi_access_token_configured' : $key,
                $changedSettings,
            ))),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function currentAccessTokenIndicator(): string
    {
        $meta = $this->pixel(MarketingPixelPlatform::Facebook);

        if (! filled($meta->getRawOriginal('meta_capi_access_token'))) {
            return __('admin.marketing_pixels.states.meta_capi_access_token_not_configured');
        }

        if (blank($meta->meta_capi_access_token_last4)) {
            return __('admin.marketing_pixels.states.meta_capi_access_token_ending_unavailable');
        }

        return str_repeat('•', 24).$meta->meta_capi_access_token_last4;
    }

    private function hasStoredMetaAccessToken(): bool
    {
        return filled(
            $this->pixel(MarketingPixelPlatform::Facebook)
                ->getRawOriginal('meta_capi_access_token'),
        );
    }

    private function formatRule(MarketingPixelPlatform $platform): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($platform): void {
            $trimmed = trim((string) ($value ?? ''));

            if ($trimmed === '') {
                return;
            }

            if (preg_match($platform->pattern(), $trimmed) !== 1) {
                $fail(__('admin.marketing_pixels.validation.invalid_format', [
                    'platform' => __("admin.marketing_pixels.platforms.{$platform->value}"),
                ]));
            }
        };
    }
}
