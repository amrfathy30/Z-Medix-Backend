<?php

namespace App\Filament\Resources\PageSectionResource\Pages;

use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Resources\PageSectionResource;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPageSection extends EditRecord
{
    protected static string $resource = PageSectionResource::class;

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by_admin_id'] = auth('admin')->id();

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
