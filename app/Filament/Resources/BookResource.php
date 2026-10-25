<?php

namespace App\Filament\Resources;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Filament\Resources\BookResource\Pages\ViewBook;
use App\Filament\Resources\BookResource\RelationManagers\PagesRelationManager;
use App\Models\Book;
use App\Services\Learning\BookService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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

                Section::make(__('admin.learning.book_section_pdf'))
                    ->visible(fn (Get $get): bool => $get('type') === BookType::Pdf->value)
                    ->schema([
                        TextEntry::make('pdf_file_name')
                            ->label(__('admin.learning.field_book_pdf_file_name'))
                            ->state(fn (?Book $record): ?string => $record?->pdfFileName())
                            ->placeholder('—'),
                        self::pdfPagesCountField(),
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

    public static function pdfPagesCountField(): TextInput
    {
        return TextInput::make('pdf_pages_count')
            ->label(__('admin.learning.field_book_total_pages'))
            ->numeric()
            ->disabled()
            ->saved(false)
            ->placeholder('—');
    }

    public static function uploadPdfAction(): Action
    {
        return Action::make('uploadPdf')
            ->label(fn (Book $record): string => $record->hasPdf()
                ? __('admin.learning.action_replace_pdf')
                : __('admin.learning.action_upload_pdf'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (Book $record): bool => $record->isPdf())
            ->authorize(fn (Book $record): bool => self::canEdit($record))
            ->schema([
                FileUpload::make('pdf')
                    ->label(__('admin.learning.field_book_pdf'))
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes(self::pdfMimeTypes())
                    ->maxSize(self::pdfMaxSizeKb())
                    ->helperText(__('admin.learning.book_pdf_upload_hint', [
                        'size' => (int) round(self::pdfMaxSizeKb() / 1024),
                    ])),
            ])
            ->action(function (array $data, Book $record, BookService $books, EditBook|ViewBook $livewire): void {
                $file = $data['pdf'];
                $file = is_array($file) ? reset($file) : $file;

                if (! $file instanceof UploadedFile) {
                    return;
                }

                try {
                    $books->replacePdf($record, $file);
                } catch (ValidationException $exception) {
                    $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();

                    throw ValidationException::withMessages([
                        $statePath.'.pdf' => $exception->errors()['pdf'],
                    ]);
                }

                $record->refresh();

                if ($livewire instanceof EditBook) {
                    $livewire->refreshFormData(['pdf_pages_count']);
                }

                Notification::make()
                    ->title(__('admin.learning.book_pdf_uploaded'))
                    ->success()
                    ->send();
            });
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
