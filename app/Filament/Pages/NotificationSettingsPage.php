<?php

namespace App\Filament\Pages;

use App\Enums\SettingValueType;
use App\Models\Setting;
use App\Services\Notifications\FcmTopicService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

/**
 * @property-read Schema $form
 */
class NotificationSettingsPage extends Page
{
    protected string $view = 'filament.pages.notification-settings-page';

    protected static ?string $slug = 'notification-settings';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public const SETTING_FCM_ENABLED = 'fcm_enabled';
    public const SETTING_IN_APP_ENABLED = 'in_app_enabled';
    public const SETTING_TOPICS = 'notification_topics';
    public const SETTING_VAPID_KEY = 'fcm_web_vapid_key';
    public const SETTING_PROJECT_ID = 'fcm_project_id';
    public const SETTING_SENDER_ID = 'fcm_sender_id';

    public const DEFAULT_TOPICS = [
        'all_students',
        'announcements',
        'exams',
        'study_reminders',
        'fellowships',
    ];

    public static function getNavigationLabel(): string
    {
        return __('notifications.settings.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('notifications.navigation_group');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check();
    }

    public function getTitle(): string|Htmlable
    {
        return __('notifications.settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('notifications.settings.subheading');
    }

    public function mount(): void
    {
        $this->form->fill($this->currentFormState());
    }

    /**
     * @return array<string, mixed>
     */
    private function currentFormState(): array
    {
        $settings = Setting::query()
            ->whereIn('key', [
                self::SETTING_FCM_ENABLED,
                self::SETTING_IN_APP_ENABLED,
                self::SETTING_TOPICS,
                self::SETTING_VAPID_KEY,
                self::SETTING_PROJECT_ID,
                self::SETTING_SENDER_ID,
            ])
            ->pluck('value', 'key');

        $topics = isset($settings[self::SETTING_TOPICS])
            ? (json_decode((string) $settings[self::SETTING_TOPICS], true) ?: self::DEFAULT_TOPICS)
            : self::DEFAULT_TOPICS;

        return [
            self::SETTING_FCM_ENABLED => isset($settings[self::SETTING_FCM_ENABLED])
                ? filter_var($settings[self::SETTING_FCM_ENABLED], FILTER_VALIDATE_BOOLEAN)
                : true,
            self::SETTING_IN_APP_ENABLED => isset($settings[self::SETTING_IN_APP_ENABLED])
                ? filter_var($settings[self::SETTING_IN_APP_ENABLED], FILTER_VALIDATE_BOOLEAN)
                : true,
            self::SETTING_TOPICS => $topics,
            self::SETTING_VAPID_KEY => $settings[self::SETTING_VAPID_KEY] ?? null,
            self::SETTING_PROJECT_ID => $settings[self::SETTING_PROJECT_ID] ?? config('firebase.default', 'app'),
            self::SETTING_SENDER_ID => $settings[self::SETTING_SENDER_ID] ?? null,
        ];
    }

    private function credentialsPath(): string
    {
        return (string) config('firebase.projects.app.credentials', storage_path('app/firebase/service-account.json'));
    }

    public function form(Schema $schema): Schema
    {
        $credentialsExist = File::exists($this->credentialsPath());

        return $schema
            ->components([
                Section::make(__('notifications.settings.service_controls_section'))
                    ->schema([
                        Toggle::make(self::SETTING_FCM_ENABLED)
                            ->label(__('notifications.settings.fcm_enabled_label'))
                            ->helperText(__('notifications.settings.fcm_enabled_helper'))
                            ->default(true),

                        Toggle::make(self::SETTING_IN_APP_ENABLED)
                            ->label(__('notifications.settings.in_app_enabled_label'))
                            ->helperText(__('notifications.settings.in_app_enabled_helper'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(__('notifications.settings.topics_section'))
                    ->schema([
                        TagsInput::make(self::SETTING_TOPICS)
                            ->label(__('notifications.settings.topics_label'))
                            ->helperText(__('notifications.settings.topics_helper'))
                            ->suggestions(self::DEFAULT_TOPICS)
                            ->default(self::DEFAULT_TOPICS)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('notifications.settings.web_config_section'))
                    ->schema([
                        TextInput::make(self::SETTING_VAPID_KEY)
                            ->label(__('notifications.settings.vapid_key_label'))
                            ->helperText(__('notifications.settings.vapid_key_helper'))
                            ->placeholder('BN5qY...')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make(self::SETTING_PROJECT_ID)
                            ->label(__('notifications.settings.project_id_label'))
                            ->placeholder('z-medix-app')
                            ->maxLength(255),

                        TextInput::make(self::SETTING_SENDER_ID)
                            ->label(__('notifications.settings.sender_id_label'))
                            ->placeholder('108523...')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make(__('notifications.settings.status_section'))
                    ->schema([
                        Placeholder::make('credentials_status')
                            ->label(__('notifications.settings.status_label'))
                            ->content(fn (): string => $credentialsExist
                                ? '🟢 ' . __('notifications.settings.configured')
                                : '🔴 ' . __('notifications.settings.not_configured')
                            ),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $definitions = [
            self::SETTING_FCM_ENABLED => SettingValueType::Boolean,
            self::SETTING_IN_APP_ENABLED => SettingValueType::Boolean,
            self::SETTING_TOPICS => SettingValueType::Json,
            self::SETTING_VAPID_KEY => SettingValueType::String,
            self::SETTING_PROJECT_ID => SettingValueType::String,
            self::SETTING_SENDER_ID => SettingValueType::String,
        ];

        foreach ($definitions as $key => $type) {
            $value = $state[$key] ?? null;

            $storedValue = match ($type) {
                SettingValueType::Boolean => $value ? '1' : '0',
                SettingValueType::Json => is_array($value) ? json_encode(array_values($value)) : json_encode([]),
                default => (string) ($value ?? ''),
            };

            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $storedValue,
                    'value_type' => $type,
                    'group' => 'notifications',
                ]
            );
        }

        Notification::make()
            ->title(__('notifications.settings.saved'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('notifications.settings.save'))
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('save'),

            Action::make('testConnection')
                ->label(__('notifications.settings.test_connection'))
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->action(function (): void {
                    $path = $this->credentialsPath();

                    if (! File::exists($path)) {
                        Notification::make()
                            ->title(__('notifications.settings.not_configured'))
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $factory = (new Factory)->withServiceAccount($path);
                        $factory->createMessaging();

                        Notification::make()
                            ->title(__('notifications.settings.connection_success'))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title(__('notifications.settings.connection_failed', ['error' => $e->getMessage()]))
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('sendTest')
                ->label(__('notifications.settings.send_test'))
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->schema([
                    Radio::make('test_target_type')
                        ->label(__('notifications.settings.test_target_type'))
                        ->options([
                            'token' => __('notifications.settings.test_token'),
                            'topic' => __('notifications.settings.test_topic'),
                        ])
                        ->default('token')
                        ->live(),

                    TextInput::make('test_token')
                        ->label(__('notifications.settings.test_token'))
                        ->required(fn ($get): bool => $get('test_target_type') === 'token')
                        ->visible(fn ($get): bool => $get('test_target_type') === 'token')
                        ->maxLength(500),

                    TextInput::make('test_topic')
                        ->label(__('notifications.settings.test_topic'))
                        ->default('all_students')
                        ->required(fn ($get): bool => $get('test_target_type') === 'topic')
                        ->visible(fn ($get): bool => $get('test_target_type') === 'topic')
                        ->maxLength(255),

                    TextInput::make('test_title')
                        ->label(__('notifications.settings.test_title'))
                        ->default('Test Push Notification')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('test_body')
                        ->label(__('notifications.settings.test_body'))
                        ->default('This is a test notification from Z-Medix dashboard.')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data, FcmTopicService $topicService, Messaging $messaging): void {
                    try {
                        $title = (string) ($data['test_title'] ?? 'Test');
                        $body = (string) ($data['test_body'] ?? 'Test Body');

                        if (($data['test_target_type'] ?? '') === 'topic') {
                            $topic = (string) ($data['test_topic'] ?? 'all_students');
                            $topicService->sendToTopic(
                                topic: $topic,
                                title: $title,
                                body: $body,
                                data: ['category' => 'test', 'sent_at' => now()->toIso8601String()]
                            );
                        } else {
                            $token = (string) ($data['test_token'] ?? '');
                            $message = CloudMessage::new()
                                ->withToken($token)
                                ->withNotification(FcmNotification::create($title, $body))
                                ->withData(['category' => 'test', 'sent_at' => now()->toIso8601String()]);

                            $messaging->send($message);
                        }

                        Notification::make()
                            ->title(__('notifications.settings.test_sent_success'))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title(__('notifications.settings.test_sent_failed', ['error' => $e->getMessage()]))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
