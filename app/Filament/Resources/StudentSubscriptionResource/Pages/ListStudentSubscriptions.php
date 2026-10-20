<?php

namespace App\Filament\Resources\StudentSubscriptionResource\Pages;

use App\Exceptions\Subscription\SubscriptionException;
use App\Filament\Resources\StudentSubscriptionResource;
use App\Models\User;
use App\Support\Filament\SubscriptionPeriodForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListStudentSubscriptions extends ListRecords
{
    protected static string $resource = StudentSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->newSubscriptionAction(),
        ];
    }

    /**
     * Starts a subscription for a student who paid without sending a request in
     * the app: the admin picks the student and records the first payment. For a
     * student who already has a subscription this simply adds a period to it.
     */
    private function newSubscriptionAction(): Action
    {
        return Action::make('newSubscription')
            ->label(__('admin.student_subscriptions.action_new'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => StudentSubscriptionResource::canCreate())
            ->modalHeading(__('admin.student_subscriptions.new_heading'))
            ->fillForm(SubscriptionPeriodForm::defaults())
            ->schema([
                Select::make('student_id')
                    ->label(__('admin.subscriptions.field_student'))
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where(fn (Builder $query): Builder => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(20)
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->getKey() => "{$user->name} ({$user->email})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($user = User::query()->find($value)) === null
                        ? null
                        : "{$user->name} ({$user->email})"),
                ...SubscriptionPeriodForm::schema(),
            ])
            ->action(function (array $data): void {
                $student = User::query()->findOrFail($data['student_id']);

                try {
                    SubscriptionPeriodForm::record($student, $data);
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
