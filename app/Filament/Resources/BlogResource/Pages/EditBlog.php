<?php

namespace App\Filament\Resources\BlogResource\Pages;

use App\Enums\ContentStatus;
use App\Filament\Resources\BlogResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBlog extends EditRecord
{
    protected static string $resource = BlogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('admin.cms.action_publish'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== ContentStatus::Published)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => ContentStatus::Published,
                        'published_at' => $this->record->published_at ?? now(),
                        'updated_by_admin_id' => auth('admin')->id(),
                    ]);
                    Notification::make()->title(__('admin.cms.action_published'))->success()->send();
                    $this->refreshFormData(['status', 'published_at']);
                }),

            Action::make('archive')
                ->label(__('admin.cms.action_archive'))
                ->icon('heroicon-o-archive-box')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === ContentStatus::Published)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update([
                        'status' => ContentStatus::Archived,
                        'updated_by_admin_id' => auth('admin')->id(),
                    ]);
                    Notification::make()->title(__('admin.cms.action_archived'))->success()->send();
                    $this->refreshFormData(['status']);
                }),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by_admin_id'] = auth('admin')->id();

        return $data;
    }
}
