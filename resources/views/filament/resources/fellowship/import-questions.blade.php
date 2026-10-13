{{--
    The import wizard's three steps. Only one is on screen at a time, driven by
    the page's `$step`.

    Nothing on the Upload or Mapping step writes a question or a filter — that is
    Confirm's job alone, which is why the admin can map, look and re-map freely.
--}}
<x-filament-panels::page>
    @php
        $import = $this->currentImport();
        $map = $this->headerMap();
        $missing = $map->missingRequiredTargets();
    @endphp

    {{-- Where the admin is, and what is left. --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('admin.fellowship.import_progress_heading') }}</x-slot>

        <ol class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
            @foreach ([
                \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_UPLOAD => __('admin.fellowship.import_step_upload'),
                \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_MAPPING => __('admin.fellowship.import_step_mapping'),
                \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_REVIEW => __('admin.fellowship.import_step_review'),
            ] as $key => $label)
                <li @class([
                    'flex items-center gap-2',
                    'font-semibold text-primary-600 dark:text-primary-400' => $this->step === $key,
                    'text-gray-500 dark:text-gray-400' => $this->step !== $key,
                ])>
                    <span class="flex size-6 items-center justify-center rounded-full border text-xs">
                        {{ $loop->iteration }}
                    </span>
                    {{ $label }}
                </li>
            @endforeach
        </ol>
    </x-filament::section>

    @if ($this->step === \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_UPLOAD)
        <form wire:submit="submitUpload">
            {{ $this->uploadForm }}

            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                    {{ __('admin.fellowship.action_read_columns') }}
                </x-filament::button>
            </div>
        </form>
    @endif

    @if ($this->step === \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_MAPPING)
        @php
            $detectedHeaders = $this->headers();
            $claimedHeaders = $this->headersClaimedBySystemFields();
            $samples = $this->sampleValues();
        @endphp

        {{-- What was actually read out of the file. Shown before the dropdowns
             because a blank or shifted header row is far easier to recognise
             here than to deduce from an empty dropdown. --}}
        <x-filament::section collapsible>
            <x-slot name="heading">
                {{ __('admin.fellowship.import_headers_detected', ['file' => $import?->original_filename ?? '']) }}
            </x-slot>

            <x-slot name="afterHeader">
                <x-filament::badge :color="$detectedHeaders === [] ? 'danger' : 'success'">
                    {{ __('admin.fellowship.import_headers_detected_count', ['count' => count($detectedHeaders)]) }}
                </x-filament::badge>
            </x-slot>

            <div class="flex flex-wrap gap-2">
                @foreach ($detectedHeaders as $header)
                    <x-filament::badge :color="in_array($header, $claimedHeaders, true) ? 'primary' : 'gray'">
                        {{ $header }}@isset($samples[$header])<span class="opacity-60"> — {{ $samples[$header] }}</span>@endisset
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        <form wire:submit="submitMapping">
            {{ $this->mappingForm }}

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <x-filament::button type="button" color="gray" wire:click="startOver">
                    {{ __('admin.fellowship.action_choose_another_file') }}
                </x-filament::button>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- Which required fields are still without a column, so the
                         admin is not left guessing why the step will not pass. --}}
                    @foreach ($this->unmappedRequiredFields() as $label)
                        <x-filament::badge color="danger">{{ $label }}</x-filament::badge>
                    @endforeach

                    <x-filament::button
                        type="submit"
                        icon="heroicon-o-magnifying-glass"
                        :disabled="! $this->isMappingComplete()"
                    >
                        {{ __('admin.fellowship.action_validate_rows') }}
                    </x-filament::button>
                </div>
            </div>
        </form>
    @endif

    @if ($this->step === \App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions::STEP_REVIEW && $import)
        {{-- What staging made of the file. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('admin.fellowship.import_summary_heading') }}</x-slot>
            <x-slot name="description">{{ $import->original_filename }}</x-slot>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['label' => __('admin.fellowship.field_import_total_rows'), 'value' => $import->total_rows, 'color' => 'gray'],
                    ['label' => __('admin.fellowship.summary_importable'), 'value' => $import->valid_rows, 'color' => 'success'],
                    ['label' => __('admin.fellowship.field_import_duplicate_rows'), 'value' => $import->duplicate_rows, 'color' => 'warning'],
                    ['label' => __('admin.fellowship.field_import_invalid_rows'), 'value' => $import->invalid_rows, 'color' => 'danger'],
                ] as $stat)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold">{{ $stat['value'] }}</div>
                    </div>
                @endforeach
            </div>

            @php $unresolved = $this->unresolvedDuplicatesCount(); @endphp

            @if ($missing !== [] || $unresolved > 0)
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($missing !== [])
                        <x-filament::badge color="danger">
                            {{ __('admin.fellowship.import_mapping_incomplete') }}
                        </x-filament::badge>
                    @endif

                    @if ($unresolved > 0)
                        <x-filament::badge color="danger">
                            {{ __('admin.fellowship.import_duplicates_undecided_body', ['count' => $unresolved]) }}
                        </x-filament::badge>
                    @endif
                </div>
            @endif
        </x-filament::section>

        {{-- Rejected rows, with their reasons. They are excluded; the rest of
             the file still goes in. --}}
        @if ($this->invalidRows()->isNotEmpty())
            <x-filament::section collapsible>
                <x-slot name="heading">{{ __('admin.fellowship.invalid_rows_heading') }}</x-slot>
                <x-slot name="description">{{ __('admin.fellowship.invalid_rows_hint') }}</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-start text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pe-4">{{ __('admin.fellowship.field_import_row_number') }}</th>
                                <th class="py-2 pe-4">{{ __('admin.fellowship.field_question_text') }}</th>
                                <th class="py-2">{{ __('admin.fellowship.field_import_errors') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->invalidRows() as $row)
                                <tr>
                                    <td class="py-2 pe-4 align-top font-mono">{{ $row->row_number }}</td>
                                    <td class="py-2 pe-4 align-top">{{ \Illuminate\Support\Str::limit($row->payload()->questionText, 80) ?: '—' }}</td>
                                    <td class="py-2 align-top">
                                        <ul class="list-inside list-disc space-y-1 text-danger-600 dark:text-danger-400">
                                            @foreach ($row->errors ?? [] as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Duplicates, decided one row at a time. No global action: the right
             answer differs row by row, and the side-by-side is how the admin
             tells which. --}}
        @if ($this->duplicateRows()->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">{{ __('admin.fellowship.duplicate_rows_heading') }}</x-slot>
                <x-slot name="description">{{ __('admin.fellowship.duplicate_rows_hint') }}</x-slot>

                {{-- Select several rows and give them all one decision. Update
                     Existing only appears while every ticked row can take it,
                     so a bulk action never silently skips part of a selection. --}}
                <div class="mb-6 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="flex items-center gap-2 text-sm font-medium">
                            <input
                                type="checkbox"
                                wire:model.live="selectAll"
                                class="fi-checkbox-input rounded border-gray-300 dark:border-white/20 dark:bg-white/5"
                            >
                            {{ __('admin.fellowship.bulk_select_all') }}
                        </label>

                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('admin.fellowship.bulk_selected_count', ['count' => $this->selectedCount()]) }}
                        </span>

                        @if ($this->selectedCount() > 0)
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($this->bulkDecisionOptions() as $value => $label)
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        wire:click="applyBulkDecision('{{ $value }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ $label }}
                                    </x-filament::button>
                                @endforeach

                                <x-filament::link
                                    tag="button"
                                    type="button"
                                    wire:click="clearSelection"
                                    color="gray"
                                    size="sm"
                                >
                                    {{ __('admin.fellowship.bulk_clear_selection') }}
                                </x-filament::link>
                            </div>
                        @endif
                    </div>

                    @if ($this->selectedCount() > 0 && $this->bulkDecisionOptions() === [])
                        <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">
                            {{ __('admin.fellowship.bulk_no_shared_decision') }}
                        </p>
                    @endif
                </div>

                <div class="space-y-6">
                    @foreach ($this->duplicateRows() as $row)
                        @php $comparison = $this->comparisonFor($row); @endphp

                        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10" wire:key="duplicate-row-{{ $row->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selected.{{ $row->id }}"
                                            aria-label="{{ __('admin.fellowship.bulk_select_row', ['row' => $row->row_number]) }}"
                                            class="fi-checkbox-input rounded border-gray-300 dark:border-white/20 dark:bg-white/5"
                                        >

                                        <span class="font-mono text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('admin.fellowship.field_import_row_number') }} {{ $row->row_number }}
                                        </span>

                                        @unless ($row->isDecided())
                                            <x-filament::badge color="danger">
                                                {{ __('admin.fellowship.decision_pending_badge') }}
                                            </x-filament::badge>
                                        @endunless

                                        <x-filament::badge :color="$row->duplicate_scope === \App\Enums\FellowshipImportDuplicateScope::Existing ? 'warning' : 'info'">
                                            {{ $row->duplicate_scope === \App\Enums\FellowshipImportDuplicateScope::Existing
                                                ? __('admin.fellowship.duplicate_of_existing')
                                                : __('admin.fellowship.duplicate_of_row', ['row' => $row->duplicate_of_row_number]) }}
                                        </x-filament::badge>

                                        @if ($comparison->correctAnswerConflicts)
                                            <x-filament::badge color="danger">
                                                {{ __('admin.fellowship.conflict_correct_answer') }}
                                            </x-filament::badge>
                                        @endif

                                        @if ($comparison->optionsConflict)
                                            <x-filament::badge color="danger">
                                                {{ __('admin.fellowship.conflict_options') }}
                                            </x-filament::badge>
                                        @endif
                                    </div>

                                    <p class="mt-2 max-w-3xl">{{ $row->payload()->questionText }}</p>
                                </div>

                                <div class="w-56">
                                    <label class="mb-1 block text-sm font-medium" for="decision-{{ $row->id }}">
                                        {{ __('admin.fellowship.field_decision') }}
                                    </label>
                                    <select
                                        id="decision-{{ $row->id }}"
                                        wire:model.live="decisions.{{ $row->id }}"
                                        class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/20 dark:bg-white/5"
                                    >
                                        @foreach ($this->decisionOptions($row) as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>

                                    @unless ($row->canUpdateExisting())
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('admin.fellowship.decision_update_unavailable') }}
                                        </p>
                                    @endunless
                                </div>
                            </div>

                            {{-- Field by field, stored against imported. A field
                                 this import did not map is marked as such: an
                                 update leaves it alone. --}}
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-start text-gray-500 dark:text-gray-400">
                                        <tr>
                                            <th class="py-2 pe-4">{{ __('admin.fellowship.comparison_field') }}</th>
                                            <th class="py-2 pe-4">{{ __('admin.fellowship.comparison_existing') }}</th>
                                            <th class="py-2">{{ __('admin.fellowship.comparison_imported') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                        @foreach ($comparison->fields as $field)
                                            <tr @class(['bg-danger-50/50 dark:bg-danger-500/10' => $field['conflicts']])>
                                                <td class="py-2 pe-4 align-top font-medium">
                                                    {{ $field['label'] }}
                                                    @unless ($field['in_import'])
                                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                                                            {{ __('admin.fellowship.comparison_not_in_import') }}
                                                        </span>
                                                    @endunless
                                                </td>
                                                <td class="py-2 pe-4 align-top">{{ $field['existing'] ?? '—' }}</td>
                                                <td class="py-2 align-top">{{ $field['in_import'] ? ($field['imported'] ?? '—') : '—' }}</td>
                                            </tr>
                                        @endforeach

                                        @foreach ($comparison->options as $index => $option)
                                            <tr @class(['bg-danger-50/50 dark:bg-danger-500/10' => $option['conflicts']])>
                                                <td class="py-2 pe-4 align-top font-medium">
                                                    {{ __('admin.fellowship.comparison_option', ['number' => $index + 1]) }}
                                                </td>
                                                <td class="py-2 pe-4 align-top">
                                                    {{ $option['existing'] ?? '—' }}
                                                    @if ($option['existing_is_correct'])
                                                        <x-filament::badge color="success" class="mt-1">
                                                            {{ __('admin.fellowship.comparison_correct') }}
                                                        </x-filament::badge>
                                                    @endif
                                                </td>
                                                <td class="py-2 align-top">
                                                    {{ $option['imported'] ?? '—' }}
                                                    @if ($option['imported_is_correct'])
                                                        <x-filament::badge color="success" class="mt-1">
                                                            {{ __('admin.fellowship.comparison_correct') }}
                                                        </x-filament::badge>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div class="flex flex-wrap justify-between gap-3">
            <x-filament::button type="button" color="gray" wire:click="backToMapping">
                {{ __('admin.fellowship.action_back_to_mapping') }}
            </x-filament::button>

            <div class="flex flex-col items-end gap-2">
                <x-filament::button
                    type="button"
                    wire:click="confirm"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-check"
                    :disabled="! $this->canConfirm()"
                >
                    {{ __('admin.fellowship.action_confirm_import') }}
                </x-filament::button>

                @if ($unresolved > 0)
                    <p class="text-sm text-danger-600 dark:text-danger-400">
                        {{ __('admin.fellowship.import_duplicates_undecided_body', ['count' => $unresolved]) }}
                    </p>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
