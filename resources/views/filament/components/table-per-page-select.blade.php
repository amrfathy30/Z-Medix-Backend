@php
    $isRtl = __('filament-panels::layout.direction') === 'rtl';
@endphp

@if (count($pageOptions) > 1)
    <label
        class="fi-pagination-records-per-page-select fi-compact"
        @if ($isRtl) dir="rtl" @endif
    >
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="tableRecordsPerPage">
                @foreach ($pageOptions as $option)
                    <option value="{{ $option }}">
                        @if ($option === 'all')
                            {{ __('filament::components/pagination.fields.records_per_page.options.all') }}
                        @else
                            {{ $option }}
                        @endif
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <span class="fi-sr-only">
            {{ __('filament::components/pagination.fields.records_per_page.label') }}
        </span>
    </label>
@endif
