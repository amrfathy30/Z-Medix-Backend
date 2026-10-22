<?php

namespace App\Filament\Widgets;

use App\Enums\ContactMessageStatus;
use App\Filament\Resources\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestContactMessagesWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.latest_inquiries_title'))
            ->query(
                ContactMessage::query()->latest()->limit(5)
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.dashboard.col_sender'))
                    ->description(fn (ContactMessage $record): string => (string) $record->email)
                    ->searchable(false)
                    ->sortable(false),

                TextColumn::make('subject')
                    ->label(__('admin.dashboard.col_subject'))
                    ->limit(25)
                    ->tooltip(fn (ContactMessage $record): string => (string) ($record->subject ?: $record->message)),

                TextColumn::make('status')
                    ->label(__('admin.dashboard.col_status'))
                    ->badge()
                    ->color(fn (ContactMessageStatus $state): string => match ($state) {
                        ContactMessageStatus::New => 'info',
                        ContactMessageStatus::InReview => 'warning',
                        ContactMessageStatus::Replied => 'success',
                        ContactMessageStatus::Closed => 'gray',
                        ContactMessageStatus::Spam => 'danger',
                    })
                    ->formatStateUsing(fn (ContactMessageStatus $state): string => match ($state) {
                        ContactMessageStatus::New => __('admin.cms.contact_status_new'),
                        ContactMessageStatus::InReview => __('admin.cms.contact_status_in_review'),
                        ContactMessageStatus::Replied => __('admin.cms.contact_status_replied'),
                        ContactMessageStatus::Closed => __('admin.cms.contact_status_closed'),
                        ContactMessageStatus::Spam => __('admin.cms.contact_status_spam'),
                    }),

                TextColumn::make('created_at')
                    ->label(__('admin.dashboard.col_date'))
                    ->since()
                    ->sortable(false),
            ])
            ->recordUrl(fn (ContactMessage $record): string => ContactMessageResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()
                    ->label(__('admin.dashboard.view_message'))
                    ->url(fn (ContactMessage $record): string => ContactMessageResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
