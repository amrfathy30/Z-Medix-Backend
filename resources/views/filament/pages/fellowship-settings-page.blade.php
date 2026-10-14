<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        @if (auth('admin')->user()?->hasPermissionTo('fellowships.update'))
            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit">
                    {{ __('admin.fellowship.action_save_settings') }}
                </x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
