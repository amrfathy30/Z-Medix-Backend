<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromoCodeResource\Pages;
use App\Filament\Resources\PromoCodeResource\RelationManagers\UsagesRelationManager;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('promo_codes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promo_codes.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.subscriptions.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withUsageCounts();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('promo_codes.configuration'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('code')->label(__('promo_codes.code'))->required()->maxLength(64)
                    ->regex('/^[a-zA-Z0-9_-]+$/')
                    ->mutateStateForValidationUsing(fn (?string $state): string => PromoCode::normalize($state ?? ''))
                    ->dehydrateStateUsing(fn (string $state): string => PromoCode::normalize($state))
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->helperText(__('promo_codes.code_hint')),
                TextInput::make('discount_percentage')->label(__('promo_codes.discount_percentage'))
                    ->numeric()->rules(['decimal:0,2'])->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->required(),
                DateTimePicker::make('valid_from')->label(__('promo_codes.valid_from'))->required(),
                DateTimePicker::make('valid_until')->label(__('promo_codes.valid_until'))->afterOrEqual('valid_from')->required(),
                TextInput::make('max_usage_limit')->label(__('promo_codes.max_usage_limit'))->integer()->minValue(1)->maxValue(4294967295)->required(),
                Toggle::make('is_active')->label(__('promo_codes.is_active'))->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('promo_codes.configuration'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('code')->label(__('promo_codes.code')),
                TextEntry::make('discount_percentage')->label(__('promo_codes.discount_percentage'))->suffix('%'),
                IconEntry::make('is_active')->label(__('promo_codes.is_active'))->boolean(),
                TextEntry::make('valid_from')->label(__('promo_codes.valid_from'))->dateTime(),
                TextEntry::make('valid_until')->label(__('promo_codes.valid_until'))->dateTime(),
                TextEntry::make('max_usage_limit')->label(__('promo_codes.max_usage_limit')),
                TextEntry::make('consumed_count')->label(__('promo_codes.consumed_count')),
                TextEntry::make('reserved_count')->label(__('promo_codes.reserved_count')),
                TextEntry::make('available_count')->label(__('promo_codes.available_count')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('promo_codes.code'))->searchable()->sortable(),
            TextColumn::make('discount_percentage')->label(__('promo_codes.discount_percentage'))->suffix('%'),
            TextColumn::make('valid_from')->label(__('promo_codes.valid_from'))->dateTime()->toggleable(),
            TextColumn::make('valid_until')->label(__('promo_codes.valid_until'))->dateTime(),
            TextColumn::make('max_usage_limit')->label(__('promo_codes.max_usage_limit')),
            TextColumn::make('consumed_count')->label(__('promo_codes.consumed_count')),
            TextColumn::make('reserved_count')->label(__('promo_codes.reserved_count')),
            TextColumn::make('available_count')->label(__('promo_codes.available_count')),
            IconColumn::make('is_active')->label(__('promo_codes.is_active'))->boolean(),
        ])->filters([TrashedFilter::make()])->recordActions([
            ViewAction::make(), EditAction::make(),
            Action::make('toggleActive')->label(fn (PromoCode $record): string => __('promo_codes.'.($record->is_active ? 'disable' : 'enable')))
                ->authorize(fn (PromoCode $record): bool => static::canEdit($record))
                ->visible(fn (PromoCode $record): bool => ! $record->trashed())
                ->action(fn (PromoCode $record) => $record->update(['is_active' => ! $record->is_active])),
            DeleteAction::make(), RestoreAction::make(),
        ])->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [UsagesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'view' => Pages\ViewPromoCode::route('/{record}'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
