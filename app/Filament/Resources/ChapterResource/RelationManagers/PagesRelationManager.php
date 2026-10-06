<?php

namespace App\Filament\Resources\ChapterResource\RelationManagers;

use App\Models\ChapterPage;
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
 * Study pages of the chapter being viewed. Creating from here assigns chapter_id
 * through the relationship.
 *
 * A page has no title: it is identified by its position, shown as "Page 1",
 * "Page 2", … and changed by dragging rows rather than typing an order.
 */
class PagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learning.pages_section');
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
                    ->label(__('admin.learning.field_page_content'))
                    ->helperText(__('admin.learning.page_create_hint'))
                    ->fileAttachmentsDisk('filament_public')
                    ->fileAttachmentsDirectory('chapter-pages')
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
                    ->state(fn (ChapterPage $record): string => $record->pageLabel()),
                TextEntry::make('content')
                    ->label(__('admin.learning.field_page_content'))
                    ->html()
                    ->placeholder('—')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ChapterPage $record): string => $record->pageLabel())
            ->columns([
                TextColumn::make('page_number')
                    ->label(__('admin.learning.field_page_order'))
                    ->state(fn (ChapterPage $record): string => $record->pageLabel())
                    ->tooltip(__('admin.learning.reorder_hint')),
                TextColumn::make('content')
                    ->label(__('admin.learning.field_page_content'))
                    ->state(fn (ChapterPage $record): string => Str::limit(strip_tags((string) $record->content), 80))
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
