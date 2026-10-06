<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\SubjectResource\Pages\CreateSubject;
use App\Filament\Resources\SubjectResource\Pages\EditSubject;
use App\Filament\Resources\SubjectResource\Pages\ListSubjects;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Subject;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubjectResource extends Resource
{
    protected static ?string $model = Subject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('admin.learning.subjects_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.learning.subjects_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learning.subjects_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.learning.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.subject_section_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.learning.field_subject_name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('admin.learning.field_status'))
                            ->options(self::statusOptions())
                            ->required()
                            ->default(ContentStatus::Draft->value),
                    ])
                    ->columns(2),

                Section::make(__('admin.learning.subject_section_image'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('subject_image')
                            ->label(__('admin.learning.field_subject_image'))
                            ->collection(Subject::IMAGE_COLLECTION)
                            ->image()
                            ->acceptedFileTypes(config('media.image_mime_types', []))
                            ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('subject_image')
                    ->label(__('admin.learning.field_subject_image'))
                    ->collection(Subject::IMAGE_COLLECTION)
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->label(__('admin.learning.field_subject_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('chapters_count')
                    ->label(__('admin.learning.field_chapters_count'))
                    ->counts('chapters')
                    ->badge()
                    ->sortable(),
                TextColumn::make('books_count')
                    ->label(__('admin.learning.books_section'))
                    ->counts('books')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (ContentStatus $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('students_count')
                    ->label(__('admin.learning.field_students_count'))
                    ->state(fn (Subject $record): int => $record->studentsCount())
                    ->tooltip(__('admin.learning.students_count_placeholder_hint'))
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->options(self::statusOptions()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            ContentStatus::Draft->value => __('admin.cms.status_draft'),
            ContentStatus::Published->value => __('admin.cms.status_published'),
            ContentStatus::Archived->value => __('admin.cms.status_archived'),
        ];
    }

    public static function statusLabel(ContentStatus $status): string
    {
        return self::statusOptions()[$status->value];
    }

    public static function statusColor(ContentStatus $status): string
    {
        return match ($status) {
            ContentStatus::Published => 'success',
            ContentStatus::Draft => 'warning',
            ContentStatus::Archived => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [
            'chapters' => ChaptersRelationManager::class,
            'books' => BooksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubjects::route('/'),
            'create' => CreateSubject::route('/create'),
            'view' => ViewSubject::route('/{record}'),
            'edit' => EditSubject::route('/{record}/edit'),
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
