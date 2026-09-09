<x-filament-panels::page>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('admin.cms.website_content_description') }}
    </p>

    <div class="grid w-full grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getCards() as $card)
            <div class="flex min-h-[180px] flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ $card['title'] }}
                    </h4>
                    <span class="fi-badge inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-200 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20">
                        {{ $card['type'] }}
                    </span>
                </div>

                <div class="mt-2 flex-1">
                    @if ($card['key'] === 'footer')
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ __('admin.cms.website_content_footer_helper') }}
                        </p>
                    @elseif ($card['disabled'])
                        <p class="text-xs text-warning-600 dark:text-warning-400">
                            {{ __('admin.cms.website_content_not_seeded') }}
                        </p>
                    @endif

                    @if (! empty($card['seoUrl']))
                        <a href="{{ $card['seoUrl'] }}"
                            class="fi-btn mt-2 inline-flex w-full items-center justify-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-white/5">
                            {{ __('admin.cms.website_content_action_seo_settings') }}
                        </a>
                    @endif
                </div>

                <div class="mt-4">
                    @if ($card['disabled'])
                        <button type="button" disabled
                            class="fi-btn inline-flex w-full items-center justify-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold text-gray-400 ring-1 ring-gray-300 dark:text-gray-500 dark:ring-gray-600 cursor-not-allowed">
                            {{ $card['actionLabel'] }}
                        </button>
                    @else
                        <a href="{{ $card['url'] }}"
                            class="fi-btn inline-flex w-full items-center justify-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                            {{ $card['actionLabel'] }}
                        </a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

</x-filament-panels::page>
