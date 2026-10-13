{{--
    One import's rows as history: what staging concluded about each, the
    decision the admin made for a duplicate, and what confirmation did with it.
--}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 dark:text-gray-400">
            <tr>
                <th class="py-2 pe-4">{{ __('admin.fellowship.field_import_row_number') }}</th>
                <th class="py-2 pe-4">{{ __('admin.fellowship.field_question_text') }}</th>
                <th class="py-2 pe-4">{{ __('admin.fellowship.field_import_row_status') }}</th>
                <th class="py-2 pe-4">{{ __('admin.fellowship.field_decision') }}</th>
                <th class="py-2 pe-4">{{ __('admin.fellowship.field_import_outcome') }}</th>
                <th class="py-2">{{ __('admin.fellowship.field_import_errors') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($rows as $row)
                <tr>
                    <td class="py-2 pe-4 align-top font-mono">{{ $row->row_number }}</td>
                    <td class="py-2 pe-4 align-top">{{ \Illuminate\Support\Str::limit($row->payload()->questionText, 70) ?: '—' }}</td>
                    <td class="py-2 pe-4 align-top">
                        <x-filament::badge :color="match ($row->status) {
                            \App\Enums\FellowshipImportRowStatus::Valid => 'success',
                            \App\Enums\FellowshipImportRowStatus::Duplicate => 'warning',
                            \App\Enums\FellowshipImportRowStatus::Invalid => 'danger',
                        }">
                            {{ __('admin.fellowship.row_status_'.$row->status->value) }}
                        </x-filament::badge>
                    </td>
                    <td class="py-2 pe-4 align-top">
                        {{ $row->decision ? __('admin.fellowship.decision_'.match ($row->decision) {
                            \App\Enums\FellowshipImportRowDecision::Skip => 'skip',
                            \App\Enums\FellowshipImportRowDecision::UpdateExisting => 'update',
                            \App\Enums\FellowshipImportRowDecision::KeepBoth => 'keep_both',
                        }) : '—' }}
                    </td>
                    <td class="py-2 pe-4 align-top">
                        @if ($row->outcome)
                            <x-filament::badge :color="match ($row->outcome) {
                                \App\Enums\FellowshipImportRowOutcome::Created => 'success',
                                \App\Enums\FellowshipImportRowOutcome::Updated => 'info',
                                \App\Enums\FellowshipImportRowOutcome::Skipped => 'gray',
                                \App\Enums\FellowshipImportRowOutcome::Failed => 'danger',
                            }">
                                {{ __('admin.fellowship.outcome_'.$row->outcome->value) }}
                            </x-filament::badge>
                        @else
                            —
                        @endif
                    </td>
                    <td class="py-2 align-top">
                        @if ($row->errors)
                            <ul class="list-inside list-disc space-y-1 text-danger-600 dark:text-danger-400">
                                @foreach ($row->errors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
