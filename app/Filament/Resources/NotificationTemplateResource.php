<?php

namespace App\Filament\Resources;

use App\Enums\NotificationPriority;
use App\Enums\NotificationTemplateStatus;
use App\Filament\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Models\NotificationTemplate;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationTemplateResource extends Resource
{
    protected static ?string $model = NotificationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('notifications.templates.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('notifications.templates.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.templates.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('notifications.navigation_group');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('key')
                            ->label(__('notifications.templates.key'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->columnSpan(2),

                        Select::make('priority')
                            ->label(__('notifications.templates.priority'))
                            ->options([
                                NotificationPriority::Low->value => 'Low',
                                NotificationPriority::Normal->value => 'Normal',
                                NotificationPriority::High->value => 'High',
                                NotificationPriority::Critical->value => 'Critical',
                            ])
                            ->default(NotificationPriority::Normal->value)
                            ->required(),

                        Select::make('status')
                            ->label(__('notifications.templates.status'))
                            ->options([
                                NotificationTemplateStatus::Active->value => 'Active',
                                NotificationTemplateStatus::Inactive->value => 'Inactive',
                            ])
                            ->default(NotificationTemplateStatus::Active->value)
                            ->required(),

                        CheckboxList::make('channels')
                            ->label(__('notifications.templates.channels'))
                            ->options([
                                'fcm' => 'Push (FCM)',
                                'in_app' => 'In-App (Database)',
                                'mail' => 'Email',
                                'sms' => 'SMS',
                            ])
                            ->default(['fcm', 'in_app'])
                            ->columnSpanFull(),
                    ])
                    ->columns(4),

                Tabs::make('Locales')
                    ->tabs([
                        Tab::make(__('notifications.templates.english'))
                            ->schema([
                                TextInput::make('title.en')
                                    ->label(__('notifications.templates.title') . ' (EN)')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('body.en')
                                    ->label(__('notifications.templates.body') . ' (EN)')
                                    ->required()
                                    ->rows(4),
                            ]),
                        Tab::make(__('notifications.templates.arabic'))
                            ->schema([
                                TextInput::make('title.ar')
                                    ->label(__('notifications.templates.title') . ' (AR)')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('body.ar')
                                    ->label(__('notifications.templates.body') . ' (AR)')
                                    ->required()
                                    ->rows(4),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label(__('notifications.templates.key'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('notifications.templates.title'))
                    ->searchable(),

                TextColumn::make('priority')
                    ->label(__('notifications.templates.priority'))
                    ->badge()
                    ->color(fn (NotificationPriority $state): string => match ($state) {
                        NotificationPriority::Low => 'gray',
                        NotificationPriority::Normal => 'info',
                        NotificationPriority::High => 'warning',
                        NotificationPriority::Critical => 'danger',
                    }),

                TextColumn::make('status')
                    ->label(__('notifications.templates.status'))
                    ->badge()
                    ->color(fn (NotificationTemplateStatus $state): string => match ($state) {
                        NotificationTemplateStatus::Active => 'success',
                        NotificationTemplateStatus::Inactive => 'danger',
                    }),

                TextColumn::make('created_at')
                    ->label(__('admin.access_management.table_created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        NotificationTemplateStatus::Active->value => 'Active',
                        NotificationTemplateStatus::Inactive->value => 'Inactive',
                    ]),
                SelectFilter::make('priority')
                    ->options([
                        NotificationPriority::Low->value => 'Low',
                        NotificationPriority::Normal->value => 'Normal',
                        NotificationPriority::High->value => 'High',
                        NotificationPriority::Critical->value => 'Critical',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationTemplates::route('/'),
            'create' => CreateNotificationTemplate::route('/create'),
            'edit' => EditNotificationTemplate::route('/{record}/edit'),
        ];
    }
}
