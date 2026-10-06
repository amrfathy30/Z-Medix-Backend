<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Book;
use App\Services\Learning\BookService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

/**
 * Book workspace: an overview, then the content section its type calls for.
 *
 * A PDF book shows its single file with open/replace actions, and a prompt
 * while no file exists. A content book shows no PDF section at all — its pages
 * are rendered below by PagesRelationManager, which hides itself for PDF books.
 *
 * Content is never required: status is an administrative state, so an empty book
 * is valid whether it is a draft, published or archived.
 */
class ViewBook extends ViewRecord
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $this->record->subject_id]) => $this->record->subject?->name,
            $this->record->title,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.book_section_overview'))
                    ->key('book-overview')
                    ->schema([
                        TextEntry::make('title')
                            ->label(__('admin.learning.field_book_title')),
                        TextEntry::make('author')
                            ->label(__('admin.learning.field_book_author'))
                            ->placeholder('—'),
                        TextEntry::make('subject.name')
                            ->label(__('admin.learning.field_subject'))
                            ->url(fn (Book $record): string => SubjectResource::getUrl('view', ['record' => $record->subject_id])),
                        TextEntry::make('type')
                            ->label(__('admin.learning.field_book_type'))
                            ->badge()
                            ->color(fn (BookType $state): string => BookResource::typeColor($state))
                            ->formatStateUsing(fn (BookType $state): string => BookResource::typeLabel($state)),
                        TextEntry::make('status')
                            ->label(__('admin.learning.field_status'))
                            ->badge()
                            ->color(fn (ContentStatus $state): string => SubjectResource::statusColor($state))
                            ->formatStateUsing(fn (ContentStatus $state): string => SubjectResource::statusLabel($state)),
                        TextEntry::make('order')
                            ->label(__('admin.learning.field_book_order')),
                        TextEntry::make('created_at')
                            ->label(__('admin.learning.field_created_at'))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label(__('admin.learning.field_updated_at'))
                            ->dateTime(),
                    ])
                    ->columns(3),

                Section::make(__('admin.learning.book_section_pdf'))
                    ->key('book-pdf-section')
                    ->visible(fn (Book $record): bool => $record->isPdf())
                    ->schema([
                        // Informational only: a PDF book with no file yet is a
                        // valid book in any status, so this prompts rather than
                        // warns and blocks nothing.
                        EmptyState::make(__('admin.learning.book_pdf_missing_heading'))
                            ->key('book-pdf-missing-state')
                            ->description(__('admin.learning.book_pdf_missing_description'))
                            ->icon(Heroicon::OutlinedDocumentArrowUp)
                            ->iconColor('gray')
                            ->visible(fn (Book $record): bool => ! $record->hasPdf()),

                        Grid::make(2)
                            ->key('book-pdf-summary')
                            ->visible(fn (Book $record): bool => $record->hasPdf())
                            ->schema([
                                TextEntry::make('pdf_file_name')
                                    ->label(__('admin.learning.field_book_pdf_file_name'))
                                    ->state(fn (Book $record): ?string => $record->pdfFileName())
                                    ->placeholder('—'),
                                TextEntry::make('pdf_state')
                                    ->label(__('admin.learning.field_book_pdf'))
                                    ->state(fn (Book $record): string => __('admin.learning.book_content_pdf_available'))
                                    ->badge()
                                    ->color('success'),
                            ]),

                        Actions::make([
                            Action::make('openPdf')
                                ->label(__('admin.learning.action_open_pdf'))
                                ->icon(Heroicon::OutlinedDocumentArrowDown)
                                ->visible(fn (Book $record): bool => $record->hasPdf())
                                ->url(fn (Book $record): ?string => $record->pdfUrl())
                                ->openUrlInNewTab(),

                            // The file is handed to MediaUploadService untouched,
                            // so the book_pdf collection — not the raw disk —
                            // decides where it lands and what it replaces.
                            Action::make('uploadPdf')
                                ->label(fn (Book $record): string => $record->hasPdf()
                                    ? __('admin.learning.action_replace_pdf')
                                    : __('admin.learning.action_upload_pdf'))
                                ->icon(Heroicon::OutlinedArrowUpTray)
                                ->authorize(fn (Book $record): bool => BookResource::canEdit($record))
                                ->schema([
                                    FileUpload::make('pdf')
                                        ->label(__('admin.learning.field_book_pdf'))
                                        ->required()
                                        ->storeFiles(false)
                                        ->acceptedFileTypes(BookResource::pdfMimeTypes())
                                        ->maxSize(BookResource::pdfMaxSizeKb())
                                        ->helperText(__('admin.learning.book_pdf_upload_hint', [
                                            'size' => (int) round(BookResource::pdfMaxSizeKb() / 1024),
                                        ])),
                                ])
                                ->action(function (array $data, Book $record, BookService $books): void {
                                    $file = $data['pdf'];
                                    $file = is_array($file) ? reset($file) : $file;

                                    if (! $file instanceof UploadedFile) {
                                        return;
                                    }

                                    $books->replacePdf($record, $file);

                                    $record->unsetRelation('media');

                                    Notification::make()
                                        ->title(__('admin.learning.book_pdf_uploaded'))
                                        ->success()
                                        ->send();
                                }),
                        ])->key('book-pdf-actions'),
                    ]),
            ]);
    }
}
