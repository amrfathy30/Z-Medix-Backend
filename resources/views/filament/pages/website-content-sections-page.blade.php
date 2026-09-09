<x-filament-panels::page>

    <a href="{{ \App\Filament\Pages\WebsiteContentPage::getUrl() }}"
        class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">
        &larr; {{ __('admin.cms.website_content_sections_back') }}
    </a>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content px-6 py-6">
            @php $sections = $this->getSections(); @endphp

            @if ($sections->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('admin.cms.website_content_sections_empty') }}
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($sections as $section)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $this->getSectionTitle($section) }}
                                </h4>
                                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span>{{ __('admin.cms.website_content_field_section_key') }}: {{ $section->section_key }}</span>
                                    <span>{{ __('admin.cms.website_content_field_sort_order') }}: {{ $section->sort_order }}</span>
                                </div>
                            </div>

                            <span class="fi-badge inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset
                                @class([
                                    'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30' => $section->status->value === 'published',
                                    'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30' => $section->status->value === 'draft',
                                    'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20' => $section->status->value === 'archived',
                                ])">
                                {{ __('admin.cms.status_'.$section->status->value) }}
                            </span>

                            <a href="{{ $this->getEditUrl($section) }}"
                                class="fi-btn inline-flex items-center justify-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                                {{ __('admin.cms.website_content_action_edit') }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</x-filament-panels::page>
