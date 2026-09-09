<?php

namespace App\Filament\Resources;

use App\Enums\ContactMessageStatus;
use App\Filament\Resources\ContactMessageResource\Pages\EditContactMessage;
use App\Filament\Resources\ContactMessageResource\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessageResource\Pages\ViewContactMessage;
use App\Models\ContactMessage;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ContactMessageResource extends Resource
{
    protected static ?string $model = ContactMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.contact_messages_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.contact_messages_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.contact_messages_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_support');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.cms.section_basic'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.cms.field_contact_name'))
                            ->disabled(),
                        TextInput::make('email')
                            ->label(__('admin.cms.field_contact_email'))
                            ->disabled(),
                        TextInput::make('phone')
                            ->label(__('admin.cms.field_contact_phone'))
                            ->disabled(),
                        TextInput::make('subject')
                            ->label(__('admin.cms.field_contact_subject'))
                            ->disabled(),
                    ])
                    ->columns(2),

                Section::make(__('admin.cms.field_contact_message'))
                    ->schema([
                        Textarea::make('message')
                            ->label(__('admin.cms.field_contact_message'))
                            ->disabled()
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Admin Response')
                    ->schema([
                        Select::make('status')
                            ->label(__('admin.cms.field_contact_status'))
                            ->options([
                                ContactMessageStatus::New->value => __('admin.cms.contact_status_new'),
                                ContactMessageStatus::InReview->value => __('admin.cms.contact_status_in_review'),
                                ContactMessageStatus::Replied->value => __('admin.cms.contact_status_replied'),
                                ContactMessageStatus::Closed->value => __('admin.cms.contact_status_closed'),
                                ContactMessageStatus::Spam->value => __('admin.cms.contact_status_spam'),
                            ])
                            ->required(),
                        Textarea::make('admin_reply')
                            ->label(__('admin.cms.field_admin_reply'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.cms.section_basic'))
                    ->schema([
                        TextEntry::make('name')->label(__('admin.cms.field_contact_name')),
                        TextEntry::make('email')->label(__('admin.cms.field_contact_email')),
                        TextEntry::make('phone')->label(__('admin.cms.field_contact_phone'))->placeholder('-'),
                        TextEntry::make('subject')->label(__('admin.cms.field_contact_subject'))->placeholder('-'),
                        TextEntry::make('status')
                            ->label(__('admin.cms.field_contact_status'))
                            ->badge()
                            ->color(fn (ContactMessageStatus $state): string => match ($state) {
                                ContactMessageStatus::New => 'info',
                                ContactMessageStatus::InReview => 'warning',
                                ContactMessageStatus::Replied => 'success',
                                ContactMessageStatus::Closed => 'gray',
                                ContactMessageStatus::Spam => 'danger',
                            }),
                        TextEntry::make('created_at')->label('Received At')->dateTime(),
                    ])
                    ->columns(2),

                Section::make(__('admin.cms.field_contact_message'))
                    ->schema([
                        TextEntry::make('message')
                            ->label(__('admin.cms.field_contact_message'))
                            ->columnSpanFull(),
                    ]),

                Section::make('Admin Response')
                    ->schema([
                        TextEntry::make('admin_reply')->label(__('admin.cms.field_admin_reply'))->placeholder('-')->columnSpanFull(),
                        TextEntry::make('repliedBy.name')->label('Replied By')->placeholder('-'),
                        TextEntry::make('replied_at')->label(__('admin.cms.field_replied_at'))->dateTime()->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.cms.field_contact_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('admin.cms.field_contact_email'))
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(__('admin.cms.field_contact_subject'))
                    ->limit(40)
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label(__('admin.cms.field_contact_status'))
                    ->badge()
                    ->color(fn (ContactMessageStatus $state): string => match ($state) {
                        ContactMessageStatus::New => 'info',
                        ContactMessageStatus::InReview => 'warning',
                        ContactMessageStatus::Replied => 'success',
                        ContactMessageStatus::Closed => 'gray',
                        ContactMessageStatus::Spam => 'danger',
                    }),
                TextColumn::make('created_at')
                    ->label('Received At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.cms.field_contact_status'))
                    ->options([
                        ContactMessageStatus::New->value => __('admin.cms.contact_status_new'),
                        ContactMessageStatus::InReview->value => __('admin.cms.contact_status_in_review'),
                        ContactMessageStatus::Replied->value => __('admin.cms.contact_status_replied'),
                        ContactMessageStatus::Closed->value => __('admin.cms.contact_status_closed'),
                        ContactMessageStatus::Spam->value => __('admin.cms.contact_status_spam'),
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactMessages::route('/'),
            'view' => ViewContactMessage::route('/{record}'),
            'edit' => EditContactMessage::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
