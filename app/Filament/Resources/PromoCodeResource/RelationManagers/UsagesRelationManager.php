<?php

namespace App\Filament\Resources\PromoCodeResource\RelationManagers;

use App\Filament\Resources\PromoCodeResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PromoCodeResource::canView($ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(PromoCodeResource::canView($this->getOwnerRecord()), 403);
        parent::mount();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->heading(__('promo_codes.history'))
            ->modifyQueryUsing(function (Builder $query): Builder {
                abort_unless(PromoCodeResource::canView($this->getOwnerRecord()), 403);

                return $query->with(['user', 'subscriptionRequest']);
            })
            ->columns([
                TextColumn::make('user.name')->label(__('admin.subscriptions.field_student'))->searchable(),
                TextColumn::make('user_id')->label(__('promo_codes.student_identifier')),
                TextColumn::make('user.email')->label(__('promo_codes.email'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan_name')->label(__('admin.subscriptions.field_plan')),
                TextColumn::make('billing_cycle')->label(__('admin.subscriptions.field_billing_cycle')),
                TextColumn::make('code')->label(__('promo_codes.code'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('original_price')->label(__('promo_codes.original_price')),
                TextColumn::make('discount_percentage')->label(__('promo_codes.discount_percentage'))->suffix('%'),
                TextColumn::make('discount_amount')->label(__('promo_codes.discount_amount')),
                TextColumn::make('final_price')->label(__('promo_codes.final_price')),
                TextColumn::make('currency')->label(__('promo_codes.currency')),
                TextColumn::make('subscriptionRequest.status')->label(__('promo_codes.request_status'))->badge(),
                TextColumn::make('status')->label(__('promo_codes.usage_status'))->badge(),
                TextColumn::make('created_at')->label(__('admin.subscription_requests.field_requested_at'))->dateTime()->sortable(),
            ])->defaultSort('id', 'desc');
    }
}
