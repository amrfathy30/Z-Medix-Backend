<x-filament-panels::page>
    <a href="{{ \App\Filament\Pages\WebsiteContentPage::getUrl() }}"
        class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">
        &larr; {{ __('admin.cms.website_content_sections_back') }}
    </a>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content px-6 py-6">
            {{ $this->form }}
        </div>
    </div>
</x-filament-panels::page>
