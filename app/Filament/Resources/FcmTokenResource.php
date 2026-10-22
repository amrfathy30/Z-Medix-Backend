<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FcmTokenResource\Pages\ListFcmTokens;
use App\Models\FcmToken;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FcmTokenResource extends Resource
{
    protected static ?string $model = FcmToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('notifications.tokens.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('notifications.tokens.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.tokens.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('notifications.navigation_group');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tokenable.name')
                    ->label(__('notifications.tokens.user'))
                    ->description(fn (FcmToken $record): ?string => $record->tokenable?->email)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('device_type')
                    ->label(__('notifications.tokens.device_type'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'ios' => 'info',
                        'android' => 'success',
                        'web' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('app_type')
                    ->label(__('notifications.tokens.app_type'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('notifications.tokens.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_used_at')
                    ->label(__('notifications.tokens.last_used_at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('notifications.tokens.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('device_type')
                    ->options([
                        'ios' => 'iOS',
                        'android' => 'Android',
                        'web' => 'Web',
                    ]),
                TernaryFilter::make('is_active')
                    ->label(__('notifications.tokens.is_active')),
            ])
            ->actions([
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFcmTokens::route('/'),
        ];
    }
}
