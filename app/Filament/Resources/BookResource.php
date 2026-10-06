<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\BookResource\RelationManagers\PagesRelationManager;
use App\Models\Book;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Books are reached through their subject, never from the sidebar, so this
 * resource registers no navigation entry and no index page.
 */
class BookResource extends Resource
{
    protected static ?string $model = Book::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('admin.learning.books_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learning.books_plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.book_section_details'))
                    ->schema([
                        self::titleField(),
                        self::authorField(),
                        self::typeField(),
                        self::statusField(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function titleField(): TextInput
    {
        return TextInput::make('title')
            ->label(__('admin.learning.field_book_title'))
            ->required()
            ->maxLength(255);
    }

    public static function authorField(): TextInput
    {
        return TextInput::make('author')
            ->label(__('admin.learning.field_book_author'))
            ->maxLength(255);
    }

    /**
     * The type decides which kind of content the book may hold, so it is frozen
     * once a PDF or a page exists. The model refuses the change as well, so a
     * crafted request cannot get past this.
     */
    public static function typeField(): Select
    {
        return Select::make('type')
            ->label(__('admin.learning.field_book_type'))
            ->options(self::typeOptions())
            ->required()
            ->live()
            ->disabled(fn (?Model $record): bool => $record instanceof Book && ! $record->canChangeType())
            ->helperText(fn (?Model $record): ?string => $record instanceof Book && ! $record->canChangeType()
                ? __('admin.learning.book_type_locked_hint')
                : null);
    }

    /**
     * Status is a plain administrative state. It carries no readiness check, so
     * an empty book may be published or archived and completed afterwards.
     */
    public static function statusField(): Select
    {
        return Select::make('status')
            ->label(__('admin.learning.field_status'))
            ->options(SubjectResource::statusOptions())
            ->required()
            ->default(ContentStatus::Draft->value);
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            BookType::Pdf->value => __('admin.learning.book_type_pdf'),
            BookType::Content->value => __('admin.learning.book_type_content'),
        ];
    }

    public static function typeLabel(BookType $type): string
    {
        return self::typeOptions()[$type->value];
    }

    public static function typeColor(BookType $type): string
    {
        return match ($type) {
            BookType::Pdf => 'danger',
            BookType::Content => 'info',
        };
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
        return [
            'pages' => PagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewBook::route('/{record}'),
            'edit' => EditBook::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
