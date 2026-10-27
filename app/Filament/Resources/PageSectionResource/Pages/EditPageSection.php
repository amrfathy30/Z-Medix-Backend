<?php

namespace App\Filament\Resources\PageSectionResource\Pages;

use App\Enums\ContentStatus;
use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Resources\PageSectionResource;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;
use App\Support\Content\Forms\EnglishContentData;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditPageSection extends EditRecord
{
    protected static string $resource = PageSectionResource::class;

    #[Locked]
    public string $contentVersion = '';

    public function mount(int|string $record): void
    {
        try {
            parent::mount($record);
        } catch (MissingContentDefinitionException) {
            $this->redirectWithLegacyNotice();

            return;
        }

        $section = $this->getRecord();
        $page = $section?->page;

        if ($page === null || ! app(ContentDefinitionRegistry::class)->hasSection($page->key, $section->section_key)) {
            $this->redirectWithLegacyNotice();
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection($this->record->page->key, $this->record->section_key);
        $this->contentVersion = hash('sha256', serialize($data['data'] ?? []));
        $data['data'] = EnglishContentData::forForm($definition->items, $data['data'] ?? []);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection($this->record->page->key, $this->record->section_key);
        $stored = $this->record->fresh()->data ?? [];
        if (hash('sha256', serialize($stored)) !== $this->contentVersion) {
            throw ValidationException::withMessages(['data.data' => 'This content has changed. Reload the page before saving.']);
        }

        $data['data'] = EnglishContentData::merge($definition->items, $stored, $data['data'] ?? []);
        $data['updated_by_admin_id'] = auth('admin')->id();

        if ($this->record->page?->key === 'subscription-policy' && $this->record->section_key === 'content') {
            $data['status'] = ContentStatus::Published;
        }

        return $data;
    }

    private function redirectWithLegacyNotice(): void
    {
        Notification::make()
            ->title(__('admin.cms.legacy_section_title'))
            ->body(__('admin.cms.legacy_section_body'))
            ->warning()
            ->send();

        $this->redirect(WebsiteContentPage::getUrl());
    }
}
