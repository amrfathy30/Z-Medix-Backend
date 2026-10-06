<?php

namespace App\Filament\Resources\SubjectResource\RelationManagers;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Book;
use App\Models\Subject;
use App\Services\Learning\BookService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform books of the subject being viewed. Creating from here assigns
 * subject_id from the owner record and goes through BookService.
 *
 * A PDF book may take its file during creation; a content book gets its pages
 * from the book's own workspace afterwards. Neither is required: a book can be
 * created, published or archived while still empty, and completed later.
 */
class BooksRelationManager extends RelationManager
{
    protected static string $relationship = 'books';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learning.books_section');
    }

    /**
     * The owner's View page is the workspace for managing these records, so the
     * table stays writable there; the model policies still decide who may act.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A new book is appended to the end of the subject, so no order
                // is asked for here. Status is a free administrative choice.
                Section::make(__('admin.learning.book_section_details'))
                    ->description(__('admin.learning.book_create_hint'))
                    ->schema([
                        BookResource::titleField(),
                        BookResource::authorField(),
                        BookResource::typeField(),
                        BookResource::statusField(),
                    ])
                    ->columns(2),

                Section::make(__('admin.learning.book_section_pdf'))
                    ->visible(fn (Get $get): bool => $get('type') === BookType::Pdf->value)
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('book_pdf')
                            ->label(__('admin.learning.field_book_pdf'))
                            ->collection(Book::PDF_COLLECTION)
                            ->acceptedFileTypes(BookResource::pdfMimeTypes())
                            ->maxSize(BookResource::pdfMaxSizeKb())
                            ->helperText(__('admin.learning.book_pdf_upload_hint', [
                                'size' => (int) round(BookResource::pdfMaxSizeKb() / 1024),
                            ]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.learning.field_book_title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author')
                    ->label(__('admin.learning.field_book_author'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.learning.field_book_type'))
                    ->badge()
                    ->color(fn (BookType $state): string => BookResource::typeColor($state))
                    ->formatStateUsing(fn (BookType $state): string => BookResource::typeLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => SubjectResource::statusColor($state))
                    ->formatStateUsing(fn (ContentStatus $state): string => SubjectResource::statusLabel($state))
                    ->sortable(),
                // One informational indicator for both types: a PDF book reports
                // whether its file is there, a content book how many pages it
                // holds. An empty book is a valid book, so this never signals an
                // error — it only says what the book currently contains.
                TextColumn::make('content_state')
                    ->label(__('admin.learning.field_book_content'))
                    ->state(fn (Book $record): string => self::contentIndicator($record))
                    ->badge()
                    ->color(fn (Book $record): string => $record->hasContent() ? 'success' : 'gray'),
                TextColumn::make('order')
                    ->label(__('admin.learning.field_book_order'))
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->tooltip(__('admin.learning.reorder_hint')),
                TextColumn::make('created_at')
                    ->label(__('admin.learning.field_created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.learning.field_book_type'))
                    ->options(BookResource::typeOptions()),
                SelectFilter::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->options(SubjectResource::statusOptions()),
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, BookService $books): Book {
                        /** @var Subject $subject */
                        $subject = $this->getOwnerRecord();

                        return $books->create($subject, [
                            'title' => $data['title'] ?? null,
                            'author' => $data['author'] ?? null,
                            'type' => $data['type'] ?? null,
                            'status' => $data['status'] ?? null,
                        ]);
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('admin.learning.action_view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Book $record): string => BookResource::getUrl('view', ['record' => $record]))
                    ->authorize(fn (Book $record): bool => BookResource::canView($record)),
                Action::make('edit')
                    ->label(__('admin.learning.action_edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Book $record): string => BookResource::getUrl('edit', ['record' => $record]))
                    ->authorize(fn (Book $record): bool => BookResource::canEdit($record)),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->reorderable('order')
            ->defaultSort('order');
    }

    /**
     * Whether a PDF book has its file, or how many pages a content book holds.
     * Purely descriptive: "No PDF" and "0 Pages" are ordinary states.
     */
    public static function contentIndicator(Book $record): string
    {
        if ($record->isPdf()) {
            return $record->hasPdf()
                ? __('admin.learning.book_content_pdf_available')
                : __('admin.learning.book_content_pdf_missing');
        }

        $pages = $record->pagesCount();

        return trans_choice('admin.learning.book_content_pages_count', $pages, ['count' => $pages]);
    }
}
