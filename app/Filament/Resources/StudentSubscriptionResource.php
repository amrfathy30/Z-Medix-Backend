<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ListStudentSubscriptions;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ViewStudentSubscription;
use App\Filament\Resources\StudentSubscriptionResource\RelationManagers\PeriodsRelationManager;
use App\Models\StudentSubscription;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every student's subscription and its history of paid periods. A subscription
 * is created by recording its first payment (from a request, or "New
 * subscription" here) and is never edited by hand: renewals add periods, and the
 * only changes to the subscription itself are cancelling and reinstating it.
 */
class StudentSubscriptionResource extends Resource
{
    protected static ?string $model = StudentSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('admin.student_subscriptions.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.student_subscriptions.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.student_subscriptions.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.subscriptions.navigation_group');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.student_subscriptions.section_overview'))
                    ->schema([
                        TextEntry::make('user.name')
                            ->label(__('admin.subscriptions.field_student')),
                        TextEntry::make('user.email')
                            ->label(__('admin.student_subscriptions.field_email')),
                        TextEntry::make('status')
                            ->label(__('admin.subscriptions.field_status'))
                            ->state(fn (StudentSubscription $record): SubscriptionStatus => $record->status())
                            ->badge()
                            ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->label())
                            ->color(fn (SubscriptionStatus $state): string => self::statusColor($state)),
                        TextEntry::make('current_plan')
                            ->label(__('admin.student_subscriptions.field_current_plan'))
                            ->state(fn (StudentSubscription $record): ?string => $record->isCancelled() ? null : $record->currentPeriod()?->plan->name)
                            ->placeholder('—'),
                        TextEntry::make('covered_until')
                            ->label(__('admin.student_subscriptions.field_covered_until'))
                            ->state(fn (StudentSubscription $record) => $record->latestEndsAt())
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('cancelled_at')
                            ->label(__('admin.student_subscriptions.field_cancelled_at'))
                            ->dateTime()
                            ->visible(fn (StudentSubscription $record): bool => $record->isCancelled()),
                        TextEntry::make('cancellation_reason')
                            ->label(__('admin.student_subscriptions.field_cancellation_reason'))
                            ->placeholder('—')
                            ->visible(fn (StudentSubscription $record): bool => $record->isCancelled()),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user')->withCount('periods'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('admin.subscriptions.field_student'))
                    ->description(fn (StudentSubscription $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchStudent($query, $search)),
                TextColumn::make('current_plan')
                    ->label(__('admin.student_subscriptions.field_current_plan'))
                    ->getStateUsing(fn (StudentSubscription $record): ?string => $record->isCancelled() ? null : $record->currentPeriod()?->plan->name)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.subscriptions.field_status'))
                    ->getStateUsing(fn (StudentSubscription $record): SubscriptionStatus => $record->status())
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->label())
                    ->color(fn (SubscriptionStatus $state): string => self::statusColor($state)),
                TextColumn::make('covered_until')
                    ->label(__('admin.student_subscriptions.field_covered_until'))
                    ->getStateUsing(fn (StudentSubscription $record) => $record->latestEndsAt())
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('periods_count')
                    ->label(__('admin.student_subscriptions.field_periods'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.student_subscriptions.field_since'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.subscriptions.field_status'))
                    ->options([
                        SubscriptionStatus::Active->value => SubscriptionStatus::Active->label(),
                        SubscriptionStatus::Expired->value => SubscriptionStatus::Expired->label(),
                        SubscriptionStatus::Cancelled->value => SubscriptionStatus::Cancelled->label(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        SubscriptionStatus::Active->value => $query->active(),
                        SubscriptionStatus::Expired->value => $query->expired(),
                        SubscriptionStatus::Cancelled->value => $query->cancelled(),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Narrows a query over anything that belongs to a student (`user` relation)
     * to those whose name or email contains the text.
     */
    public static function searchStudent(Builder $query, string $search): Builder
    {
        return $query->whereHas('user', fn (Builder $student): Builder => $student
            ->where('name', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%"));
    }

    public static function statusColor(SubscriptionStatus $status): string
    {
        return match ($status) {
            SubscriptionStatus::Active => 'success',
            SubscriptionStatus::Expired => 'gray',
            SubscriptionStatus::Cancelled => 'danger',
            SubscriptionStatus::None => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [
            PeriodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentSubscriptions::route('/'),
            'view' => ViewStudentSubscription::route('/{record}'),
        ];
    }
}
