@php
    $currentLocale = app()->getLocale();
    $targetLocale = $currentLocale === 'ar' ? 'en' : 'ar';
@endphp

<div class="flex items-center me-3">
    <button
        type="button"
        wire:click="switchLocale('{{ $targetLocale }}')"
        class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:border-gray-300 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
    >
        @svg('heroicon-o-language', 'h-5 w-5 shrink-0 text-gray-500 dark:text-gray-300')
        <span class="leading-none">{{ __('admin.locale_switcher.switch_to') }}</span>
    </button>
</div>
