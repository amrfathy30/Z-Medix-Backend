<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\BookResource\RelationManagers\PagesRelationManager;
use App\Models\Book;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        self::classificationField(),
                        self::typeField(),
                        self::statusField(),
                    ])
                    ->columns(2),

                Section::make(__('admin.learning.book_section_cover'))
                    ->schema([
                        self::coverField(),
                    ]),

                // Asked for PDF books only: a content book's pages are counted
                // from its own rows, so a figure typed here could only
                // contradict them.
                Section::make(__('admin.learning.book_section_pdf'))
                    ->visible(fn (Get $get): bool => $get('type') === BookType::Pdf->value)
                    ->schema([
                        self::totalPagesField(),
                    ]),
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
     * Free text, not an entity: it groups books on the student's Books tab and
     * nothing in the application reads it as a value.
     */
    public static function classificationField(): TextInput
    {
        return TextInput::make('classification')
            ->label(__('admin.learning.field_book_classification'))
            ->maxLength(255);
    }

    /**
     * The book's cover image. Its own single-file collection, so uploading,
     * replacing or removing a cover never touches the PDF beside it.
     */
    public static function coverField(): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make('book_cover')
            ->label(__('admin.learning.field_book_cover'))
            ->collection(Book::COVER_COLLECTION)
            ->image()
            ->acceptedFileTypes(self::coverMimeTypes())
            ->maxSize(self::coverMaxSizeKb())
            ->helperText(__('admin.learning.book_cover_upload_hint', [
                'size' => (int) round(self::coverMaxSizeKb() / 1024),
            ]))
            ->columnSpanFull();
    }

    /**
     * The page count of a PDF book, typed by the admin: nothing parses the file.
     *
     * Shown inside a section the book's type gates, never on its own, so a
     * content book is not asked for a figure its page rows already answer.
     */
    public static function totalPagesField(): TextInput
    {
        return TextInput::make('total_pages')
            ->label(__('admin.learning.field_book_total_pages'))
            ->helperText(__('admin.learning.book_total_pages_hint'))
            ->numeric()
            ->minValue(1)
            ->maxValue(100000);
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

    /**
     * Accepted cover image types — JPG, PNG and WebP — from the shared media
     * config, which is also what the model's collection accepts.
     *
     * @return array<int, string>
     */
    public static function coverMimeTypes(): array
    {
        return config('media.cover_image_mime_types', []);
    }

    public static function coverMaxSizeKb(): int
    {
        return (int) config('media.max_image_size_kb', 5 * 1024);
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
