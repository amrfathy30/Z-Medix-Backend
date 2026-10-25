<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionRequestStatus;
use App\Exceptions\Subscription\SubscriptionException;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ListSubscriptionRequests;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ViewSubscriptionRequest;
use App\Models\SubscriptionRequest;
use App\Services\Subscription\SubscriptionService;
use App\Support\Filament\SubscriptionPeriodForm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The inbox of plan requests students send from the app. Nothing is charged
 * here: the admin takes payment on WhatsApp, then approves the request by
 * recording the payment (which creates the subscription period) or rejects it.
 */
class SubscriptionRequestResource extends Resource
{
    protected static ?string $model = SubscriptionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('admin.subscription_requests.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.subscription_requests.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.subscription_requests.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.subscriptions.navigation_group');
    }

    /**
     * How many requests are waiting, so the admin sees the inbox needs them.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $pending = SubscriptionRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    /**
     * Requests only arrive from the app; the admin handles them, never writes one.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'plan', 'handledBy']))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('admin.subscriptions.field_student'))
                    ->description(fn (SubscriptionRequest $record): ?string => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => StudentSubscriptionResource::searchStudent($query, $search)),
                TextColumn::make('plan')
                    ->label(__('admin.subscriptions.field_plan'))
                    ->getStateUsing(fn (SubscriptionRequest $record): string => $record->plan->name),
                TextColumn::make('billing_cycle')
                    ->label(__('admin.subscriptions.field_billing_cycle'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('status')
                    ->label(__('admin.subscriptions.field_status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label())
                    ->color(fn (SubscriptionRequestStatus $state): string => match ($state) {
                        SubscriptionRequestStatus::Pending => 'warning',
                        SubscriptionRequestStatus::Approved => 'success',
                        SubscriptionRequestStatus::Rejected => 'danger',
                    }),
                TextColumn::make('pricing_snapshot.code')->label(__('promo_codes.singular'))->placeholder('—'),
                TextColumn::make('pricing_snapshot.final_price')->label(__('promo_codes.final_price'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('admin.subscription_requests.field_requested_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('handled_at')
                    ->label(__('admin.subscription_requests.field_handled_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn (SubscriptionRequest $record): ?string => $record->handledBy?->name)
                    ->toggleable(),
                TextColumn::make('admin_note')
                    ->label(__('admin.subscription_requests.field_admin_note'))
                    ->placeholder('—')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.subscriptions.field_status'))
                    ->options(collect(SubscriptionRequestStatus::cases())
                        ->mapWithKeys(fn (SubscriptionRequestStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->default(SubscriptionRequestStatus::Pending->value),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Records the payment the admin took on WhatsApp against this request. The
     * form starts from the plan and cycle the student asked for, but the admin
     * can change both: what was agreed on the call is what gets recorded.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('admin.subscription_requests.action_approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (SubscriptionRequest $record): bool => $record->isPending())
            ->authorize(fn (SubscriptionRequest $record): bool => self::canEdit($record))
            ->modalHeading(fn (SubscriptionRequest $record): string => __('admin.subscription_requests.approve_heading', [
                'student' => $record->user?->name,
            ]))
            ->modalSubmitActionLabel(__('admin.subscription_requests.action_approve'))
            ->fillForm(fn (SubscriptionRequest $record): array => array_replace(
                SubscriptionPeriodForm::defaults($record->plan, $record->billing_cycle),
                $record->pricing_snapshot === null ? [] : [
                    'amount' => number_format($record->pricing_snapshot['final_price'], 2, '.', ''),
                    'currency' => $record->pricing_snapshot['currency'],
                ],
            ))
            ->schema(SubscriptionPeriodForm::schema())
            ->action(function (array $data, SubscriptionRequest $record): void {
                try {
                    SubscriptionPeriodForm::record($record->user, $data, $record);
                } catch (SubscriptionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.subscription_requests.approved'))
                    ->success()
                    ->send();
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('admin.subscription_requests.action_reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (SubscriptionRequest $record): bool => $record->isPending())
            ->authorize(fn (SubscriptionRequest $record): bool => self::canEdit($record))
            ->modalHeading(__('admin.subscription_requests.reject_heading'))
            ->schema([
                Textarea::make('admin_note')
                    ->label(__('admin.subscription_requests.field_admin_note'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, SubscriptionRequest $record, SubscriptionService $subscriptions): void {
                try {
                    $subscriptions->rejectRequest($record, auth('admin')->user(), $data['admin_note'] ?? null);
                } catch (SubscriptionException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('admin.subscription_requests.rejected'))
                    ->success()
                    ->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.subscription_requests.model_label'))->columns(2)->columnSpanFull()->schema([
                TextEntry::make('user.name')->label(__('admin.subscriptions.field_student')),
                TextEntry::make('plan.name')->label(__('admin.subscriptions.field_plan')),
                TextEntry::make('billing_cycle')->label(__('admin.subscriptions.field_billing_cycle')),
                TextEntry::make('status')->label(__('admin.subscriptions.field_status'))->badge(),
                TextEntry::make('admin_note')->label(__('admin.subscription_requests.field_admin_note'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.subscription_requests.field_requested_at'))->dateTime(),
            ]),
            Section::make(__('promo_codes.pricing'))->columns(3)->columnSpanFull()->schema(
                collect(['code', 'discount_percentage', 'original_price', 'discount_amount', 'final_price', 'currency'])
                    ->map(fn (string $field): TextEntry => TextEntry::make('pricing_snapshot.'.$field)->label(__('promo_codes.'.$field))->placeholder('—'))
                    ->all(),
            ),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionRequests::route('/'),
            'view' => ViewSubscriptionRequest::route('/{record}'),
        ];
    }
}
