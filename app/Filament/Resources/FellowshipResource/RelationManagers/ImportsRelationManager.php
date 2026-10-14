<?php

namespace App\Filament\Resources\FellowshipResource\RelationManagers;

use App\Enums\FellowshipImportStatus;
use App\Filament\Resources\FellowshipResource;
use App\Models\FellowshipImport;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Import History: every run of the wizard against this Fellowship, what it
 * found and what it did.
 *
 * Read-only by design. An import record is the audit trail of what entered the
 * bank and when, so it is never edited or deleted from here — a mistaken import
 * is corrected by changing the questions, not by erasing the evidence.
 *
 * A staged import, which is one the admin left mid-review, links back into the
 * wizard so they can pick it up where they stopped.
 */
class ImportsRelationManager extends RelationManager
{
    protected static string $relationship = 'imports';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fellowship.imports_section');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_filename')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.fellowship.field_import_started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label(__('admin.fellowship.field_import_file'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('admin_name')
                    ->label(__('admin.fellowship.field_import_admin'))
                    ->state(fn (FellowshipImport $record): ?string => $record->admin?->display_name)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.fellowship.field_import_status'))
                    ->badge()
                    ->color(fn (FellowshipImportStatus $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (FellowshipImportStatus $state): string => self::statusLabel($state)),
                TextColumn::make('total_rows')
                    ->label(__('admin.fellowship.field_import_total_rows'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('invalid_rows')
                    ->label(__('admin.fellowship.field_import_invalid_rows'))
                    ->badge()
                    ->color(fn (FellowshipImport $record): string => $record->invalid_rows > 0 ? 'danger' : 'gray'),
                TextColumn::make('duplicate_rows')
                    ->label(__('admin.fellowship.field_import_duplicate_rows'))
                    ->badge()
                    ->color(fn (FellowshipImport $record): string => $record->duplicate_rows > 0 ? 'warning' : 'gray'),
                TextColumn::make('created_questions')
                    ->label(__('admin.fellowship.field_import_created'))
                    ->badge()
                    ->color('success'),
                TextColumn::make('updated_questions')
                    ->label(__('admin.fellowship.field_import_updated'))
                    ->badge()
                    ->color('info'),
                TextColumn::make('skipped_questions')
                    ->label(__('admin.fellowship.field_import_skipped'))
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fellowship.field_import_status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                // Every row's per-row validation result, duplicate decision and
                // processing outcome, which is what the history is for.
                Action::make('rows')
                    ->label(__('admin.fellowship.action_view_import_rows'))
                    ->icon(Heroicon::OutlinedTableCells)
                    ->modalHeading(fn (FellowshipImport $record): string => $record->original_filename)
                    ->modalContent(fn (FellowshipImport $record) => view(
                        'filament.fellowship.import-rows',
                        ['rows' => $record->rows()->with('duplicateOfQuestion')->get()],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.fellowship.action_close')),

                Action::make('resume')
                    ->label(__('admin.fellowship.action_resume_import'))
                    ->icon(Heroicon::OutlinedArrowUturnRight)
                    ->url(fn (FellowshipImport $record): string => FellowshipResource::getUrl(
                        'import-questions',
                        ['record' => $record->fellowship_id, 'import' => $record->getKey()],
                    ))
                    ->visible(fn (FellowshipImport $record): bool => $record->isStaged()),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            FellowshipImportStatus::Draft->value => __('admin.fellowship.import_status_draft'),
            FellowshipImportStatus::Staged->value => __('admin.fellowship.import_status_staged'),
            FellowshipImportStatus::Processing->value => __('admin.fellowship.import_status_processing'),
            FellowshipImportStatus::Completed->value => __('admin.fellowship.import_status_completed'),
            FellowshipImportStatus::Failed->value => __('admin.fellowship.import_status_failed'),
        ];
    }

    public static function statusLabel(FellowshipImportStatus $status): string
    {
        return self::statusOptions()[$status->value];
    }

    public static function statusColor(FellowshipImportStatus $status): string
    {
        return match ($status) {
            FellowshipImportStatus::Completed => 'success',
            FellowshipImportStatus::Staged => 'warning',
            FellowshipImportStatus::Processing => 'info',
            FellowshipImportStatus::Failed => 'danger',
            FellowshipImportStatus::Draft => 'gray',
        };
    }
}
