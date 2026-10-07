<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportCaseResource\Pages\CreateReportCase;
use App\Filament\Resources\ReportCaseResource\Pages\EditReportCase;
use App\Filament\Resources\ReportCaseResource\Pages\ListReportCases;
use App\Models\ReportCase;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ReportCaseResource extends Resource
{
    protected static ?string $model = ReportCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('admin.report_cases.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.report_cases.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.report_cases.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.learning.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.report_cases.section_details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('admin.report_cases.field_title'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('source')
                            ->label(__('admin.report_cases.field_source'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(__('admin.report_cases.field_description'))
                            ->required()
                            ->rows(8)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('admin.report_cases.field_is_active'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(__('admin.report_cases.section_pdf'))
                    ->schema([
                        // The file is handed to the report_case_pdf collection,
                        // so the collection — not the raw disk — decides where
                        // it lands and what it replaces. On edit the stored file
                        // stands until the admin uploads a new one.
                        SpatieMediaLibraryFileUpload::make('report_case_pdf')
                            ->label(__('admin.report_cases.field_pdf'))
                            ->collection(ReportCase::PDF_COLLECTION)
                            ->acceptedFileTypes(self::pdfMimeTypes())
                            ->maxSize(self::pdfMaxSizeKb())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(__('admin.report_cases.pdf_upload_hint', [
                                'size' => (int) round(self::pdfMaxSizeKb() / 1024),
                            ]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.report_cases.field_title'))
                    ->searchable()
                    ->sortable()
                    ->limit(60),
                TextColumn::make('source')
                    ->label(__('admin.report_cases.field_source'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.report_cases.field_is_active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.report_cases.field_created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.report_cases.field_is_active')),
            ])
            ->recordActions([
                self::toggleActiveAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Flips the record's active state. Authorization goes through the policy's
     * update ability, the same as editing the record by hand.
     */
    public static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (ReportCase $record): string => $record->is_active
                ? __('admin.report_cases.action_deactivate')
                : __('admin.report_cases.action_activate'))
            ->icon(fn (ReportCase $record): Heroicon => $record->is_active
                ? Heroicon::OutlinedXCircle
                : Heroicon::OutlinedCheckCircle)
            ->color(fn (ReportCase $record): string => $record->is_active ? 'warning' : 'success')
            ->authorize(fn (ReportCase $record): bool => self::canEdit($record))
            ->requiresConfirmation()
            ->action(function (ReportCase $record): void {
                $record->update(['is_active' => ! $record->is_active]);

                Notification::make()
                    ->title($record->is_active
                        ? __('admin.report_cases.activated')
                        : __('admin.report_cases.deactivated'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Accepted PDF MIME types, from the shared media config.
     *
     * @return array<int, string>
     */
    public static function pdfMimeTypes(): array
    {
        return config('media.document_mime_types', []);
    }

    /**
     * Maximum PDF size in kilobytes, from the shared media config.
     */
    public static function pdfMaxSizeKb(): int
    {
        return (int) config('media.max_document_size_kb', 10 * 1024);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportCases::route('/'),
            'create' => CreateReportCase::route('/create'),
            'edit' => EditReportCase::route('/{record}/edit'),
        ];
    }
}
