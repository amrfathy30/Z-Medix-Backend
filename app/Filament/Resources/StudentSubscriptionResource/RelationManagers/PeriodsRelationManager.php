<?php

namespace App\Filament\Resources\StudentSubscriptionResource\RelationManagers;

use App\Exceptions\Subscription\SubscriptionException;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Support\Filament\SubscriptionPeriodForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The invoice history of a subscription: one row per payment the admin recorded,
 * newest first, each with its payment screenshot. Append-only on purpose — a
 * period is a record of money received, so it is added here and never edited or
 * deleted; a mistake is corrected by cancelling the subscription or adding the
 * right period.
 */
class PeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'periods';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.student_subscriptions.periods_section');
    }

    /**
     * The owner's View page is where periods are added, so the table stays
     * writable there; the policies still decide who may act.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->reorder()
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->with(['plan', 'recordedBy', 'media']))
            ->columns([
                TextColumn::make('plan')
                    ->label(__('admin.subscriptions.field_plan'))
                    ->getStateUsing(fn (SubscriptionPeriod $record): string => $record->plan->name),
                TextColumn::make('billing_cycle')
                    ->label(__('admin.subscriptions.field_billing_cycle'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('starts_at')
                    ->label(__('admin.subscriptions.field_starts_at_short'))
                    ->dateTime(),
                TextColumn::make('ends_at')
                    ->label(__('admin.subscriptions.field_ends_at'))
                    ->dateTime(),
                TextColumn::make('amount')
                    ->label(__('admin.subscriptions.field_amount'))
                    ->suffix(fn (SubscriptionPeriod $record): string => ' '.$record->currency),
                TextColumn::make('paid_at')
                    ->label(__('admin.subscriptions.field_paid_at'))
                    ->dateTime(),
                TextColumn::make('proof')
                    ->label(__('admin.subscriptions.field_proof_short'))
                    ->state(fn (SubscriptionPeriod $record): ?string => $record->proof() === null
                        ? null
                        : __('admin.subscriptions.view_proof'))
                    ->url(fn (SubscriptionPeriod $record): ?string => $record->proof() === null
                        ? null
                        : route('filament.admin.subscription-periods.proof', $record), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('recordedBy.name')
                    ->label(__('admin.subscriptions.field_recorded_by'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->label(__('admin.subscriptions.field_notes'))
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                $this->addPeriodAction(),
            ]);
    }

    private function addPeriodAction(): Action
    {
        return Action::make('addPeriod')
            ->label(__('admin.student_subscriptions.action_add_period'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => auth('admin')->user()?->can('create', SubscriptionPeriod::class) ?? false)
            ->modalHeading(__('admin.student_subscriptions.add_period_heading'))
            ->fillForm(fn (): array => SubscriptionPeriodForm::defaults())
            ->schema(SubscriptionPeriodForm::schema())
            ->action(function (array $data): void {
                /** @var StudentSubscription $subscription */
                $subscription = $this->getOwnerRecord();

                try {
                    SubscriptionPeriodForm::record($subscription->user, $data);
                } catch (SubscriptionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.student_subscriptions.recorded'))
                    ->success()
                    ->send();
            });
    }
}
