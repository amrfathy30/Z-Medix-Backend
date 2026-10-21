<?php

namespace App\Filament\Pages;

use App\Jobs\Notifications\SendFcmMessage;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\Notifications\FcmTopicService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * @property-read Schema $form
 */
class SendNotificationPage extends Page
{
    protected string $view = 'filament.pages.send-notification-page';

    protected static ?string $slug = 'send-notification';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('notifications.send_page.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedPaperAirplane;
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
        return __('notifications.send_page.title');
    }

    public function getSubheading(): ?string
    {
        return __('notifications.send_page.subheading');
    }

    public function mount(): void
    {
        $this->form->fill([
            'target_type' => 'topic',
            'topic' => 'all_students',
            'user_ids' => [],
            'channels' => ['fcm', 'in_app'],
            'title' => '',
            'body' => '',
            'action_url' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('notifications.send_page.target_section'))
                    ->schema([
                        Radio::make('target_type')
                            ->label(__('notifications.send_page.target_type'))
                            ->options([
                                'topic' => __('notifications.send_page.target_topic'),
                                'users' => __('notifications.send_page.target_users'),
                                'all_students' => __('notifications.send_page.target_all_students'),
                            ])
                            ->default('topic')
                            ->live()
                            ->columnSpanFull(),

                        Select::make('topic')
                            ->label(__('notifications.send_page.topic_name'))
                            ->helperText(__('notifications.send_page.topic_helper'))
                            ->options(function (): array {
                                $raw = \App\Models\Setting::query()->where('key', NotificationSettingsPage::SETTING_TOPICS)->value('value');
                                $topics = is_string($raw) ? json_decode($raw, true) : null;
                                $list = is_array($topics) && ! empty($topics) ? $topics : NotificationSettingsPage::DEFAULT_TOPICS;

                                return array_combine($list, $list);
                            })
                            ->searchable()
                            ->default('all_students')
                            ->required(fn ($get): bool => $get('target_type') === 'topic')
                            ->visible(fn ($get): bool => $get('target_type') === 'topic')
                            ->columnSpanFull(),

                        Select::make('user_ids')
                            ->label(__('notifications.send_page.users_select'))
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search): array {
                                return User::query()
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
                                    ->all();
                            })
                            ->getOptionLabelsUsing(function (array $values): array {
                                return User::query()
                                    ->whereIn('id', $values)
                                    ->get()
                                    ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
                                    ->all();
                            })
                            ->required(fn ($get): bool => $get('target_type') === 'users')
                            ->visible(fn ($get): bool => $get('target_type') === 'users')
                            ->columnSpanFull(),

                        CheckboxList::make('channels')
                            ->label(__('notifications.send_page.channels'))
                            ->options([
                                'fcm' => __('notifications.send_page.channel_fcm'),
                                'in_app' => __('notifications.send_page.channel_in_app'),
                            ])
                            ->default(['fcm', 'in_app'])
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('notifications.send_page.content_section'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('notifications.send_page.title_field'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Textarea::make('body')
                            ->label(__('notifications.send_page.body_field'))
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),

                        TextInput::make('action_url')
                            ->label(__('notifications.send_page.action_url'))
                            ->helperText(__('notifications.send_page.action_url_helper'))
                            ->placeholder('https://... or app screen route')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(FcmTopicService $topicService): void
    {
        $state = $this->form->getState();

        $channels = (array) ($state['channels'] ?? []);
        if (empty($channels)) {
            Notification::make()
                ->title(__('notifications.send_page.validation_no_channel'))
                ->warning()
                ->send();

            return;
        }

        $title = (string) ($state['title'] ?? '');
        $body = (string) ($state['body'] ?? '');
        $actionUrl = filled($state['action_url'] ?? null) ? (string) $state['action_url'] : null;
        $targetType = (string) ($state['target_type'] ?? 'topic');

        $extraData = array_filter([
            'category' => 'broadcast',
            'action_url' => $actionUrl,
            'sent_at' => now()->toIso8601String(),
        ]);

        try {
            $recipientsCount = 0;

            if ($targetType === 'topic') {
                $topic = (string) ($state['topic'] ?? '');
                if (blank($topic)) {
                    throw new \InvalidArgumentException(__('notifications.send_page.validation_no_target'));
                }

                if (in_array('fcm', $channels, true)) {
                    $topicService->sendToTopic(
                        topic: $topic,
                        title: $title,
                        body: $body,
                        data: $extraData,
                    );
                }

                $recipientsCount = 1;
            } elseif ($targetType === 'all_students') {
                // If FCM is checked, broadcast via default topic for all students
                if (in_array('fcm', $channels, true)) {
                    $topicService->sendToTopic(
                        topic: 'all_students',
                        title: $title,
                        body: $body,
                        data: $extraData,
                    );
                }

                // If In-App is checked, deliver in chunks to users
                if (in_array('in_app', $channels, true)) {
                    User::query()->chunk(200, function ($users) use ($title, $body, $actionUrl, $extraData, &$recipientsCount): void {
                        NotificationFacade::send(
                            $users,
                            new InAppNotification(
                                category: 'announcement',
                                title: $title,
                                body: $body,
                                actionUrl: $actionUrl,
                                extra: $extraData,
                            )
                        );
                        $recipientsCount += $users->count();
                    });
                } else {
                    $recipientsCount = User::count();
                }
            } elseif ($targetType === 'users') {
                $userIds = (array) ($state['user_ids'] ?? []);
                if (empty($userIds)) {
                    throw new \InvalidArgumentException(__('notifications.send_page.validation_no_target'));
                }

                $users = User::with('fcmTokens')->whereIn('id', $userIds)->get();

                if (in_array('in_app', $channels, true)) {
                    NotificationFacade::send(
                        $users,
                        new InAppNotification(
                            category: 'manual',
                            title: $title,
                            body: $body,
                            actionUrl: $actionUrl,
                            extra: $extraData,
                        )
                    );
                }

                if (in_array('fcm', $channels, true)) {
                    foreach ($users as $user) {
                        $activeTokens = $user->fcmTokens->where('is_active', true);
                        foreach ($activeTokens as $fcmToken) {
                            SendFcmMessage::dispatch(
                                token: $fcmToken->token,
                                title: $title,
                                body: $body,
                                data: $extraData,
                            );
                        }
                    }
                }

                $recipientsCount = $users->count();
            }

            Notification::make()
                ->title(__('notifications.send_page.sent_success', ['count' => $recipientsCount]))
                ->success()
                ->send();

            $this->form->fill([
                'target_type' => $targetType,
                'topic' => $state['topic'] ?? 'all_students',
                'user_ids' => [],
                'channels' => $channels,
                'title' => '',
                'body' => '',
                'action_url' => '',
            ]);
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('notifications.send_page.sent_failed', ['error' => $e->getMessage()]))
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(__('notifications.send_page.send_button'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->action('send'),
        ];
    }
}
