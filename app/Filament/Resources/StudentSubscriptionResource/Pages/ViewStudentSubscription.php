<?php

namespace App\Filament\Resources\StudentSubscriptionResource\Pages;

use App\Filament\Resources\StudentSubscriptionResource;
use App\Models\StudentSubscription;
use App\Services\Subscription\SubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * A student's subscription: who they are, where they stand, and (below, from the
 * relation manager) every payment recorded for them. Cancelling and reinstating
 * live here; renewals are added from the periods table.
 */
class ViewStudentSubscription extends ViewRecord
{
    protected static string $resource = StudentSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('admin.student_subscriptions.action_cancel'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (StudentSubscription $record): bool => ! $record->isCancelled())
                ->authorize(fn (StudentSubscription $record): bool => StudentSubscriptionResource::canEdit($record))
                ->modalHeading(__('admin.student_subscriptions.cancel_heading'))
                ->modalDescription(__('admin.student_subscriptions.cancel_description'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.student_subscriptions.field_cancellation_reason'))
                        ->rows(3)
                        ->maxLength(1000),
                ])
                ->action(function (array $data, StudentSubscription $record, SubscriptionService $subscriptions): void {
                    $subscriptions->cancel($record, $data['reason'] ?? null);

                    Notification::make()
                        ->title(__('admin.student_subscriptions.cancelled'))
                        ->success()
                        ->send();
                }),

            Action::make('reinstate')
                ->label(__('admin.student_subscriptions.action_reinstate'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('success')
                ->visible(fn (StudentSubscription $record): bool => $record->isCancelled())
                ->authorize(fn (StudentSubscription $record): bool => StudentSubscriptionResource::canEdit($record))
                ->requiresConfirmation()
                ->action(function (StudentSubscription $record, SubscriptionService $subscriptions): void {
                    $subscriptions->reinstate($record);

                    Notification::make()
                        ->title(__('admin.student_subscriptions.reinstated'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
