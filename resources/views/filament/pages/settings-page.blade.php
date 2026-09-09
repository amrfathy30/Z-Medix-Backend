<x-filament-panels::page>
    @if (empty($this->getGroupedSettings()))
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 px-6 py-8">
            <p class="text-sm text-center text-gray-500 dark:text-gray-400">
                {{ __('admin.cms.settings_no_settings') }}
            </p>
        </div>
    @else
        {{ $this->form }}
    @endif
</x-filament-panels::page>
