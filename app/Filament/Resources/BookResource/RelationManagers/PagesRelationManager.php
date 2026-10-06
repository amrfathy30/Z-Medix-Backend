<?php

namespace App\Filament\Resources\BookResource\RelationManagers;

use App\Models\Book;
use App\Models\BookPage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\RichEditor;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pages of the content book being viewed. Creating from here assigns book_id
 * through the relationship.
 *
 * Only content books hold pages, so this table is hidden entirely for a PDF
 * book. The editor, attachment handling and validation mirror chapter pages so
 * the two behave the same way for the admin.
 */
class PagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learning.book_pages_section');
    }

    /**
     * A PDF book keeps its material in its single uploaded file, so it never
     * exposes a pages table.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Book
            && $ownerRecord->isContent()
            && parent::canViewForRecord($ownerRecord, $pageClass);
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
                RichEditor::make('content')
                    ->label(__('admin.learning.field_book_content'))
                    ->helperText(__('admin.learning.book_pages_create_hint'))
                    ->fileAttachmentsDisk('filament_public')
                    ->fileAttachmentsDirectory('book-pages')
                    ->fileAttachmentsVisibility('public')
                    ->fileAttachmentsAcceptedFileTypes(config('media.image_mime_types', []))
                    ->fileAttachmentsMaxSize(config('media.max_image_size_kb', 5 * 1024))
                    ->columnSpanFull(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('page_number')
                    ->label(__('admin.learning.field_page_order'))
                    ->state(fn (BookPage $record): string => $record->pageLabel()),
                TextEntry::make('content')
                    ->label(__('admin.learning.field_book_content'))
                    ->html()
                    ->placeholder('—')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (BookPage $record): string => $record->pageLabel())
            ->columns([
                TextColumn::make('page_number')
                    ->label(__('admin.learning.field_page_order'))
                    ->state(fn (BookPage $record): string => $record->pageLabel())
                    ->tooltip(__('admin.learning.reorder_hint')),
                TextColumn::make('content')
                    ->label(__('admin.learning.field_book_content'))
                    ->state(fn (BookPage $record): string => Str::limit(strip_tags((string) $record->content), 80))
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('admin.learning.field_created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
}
