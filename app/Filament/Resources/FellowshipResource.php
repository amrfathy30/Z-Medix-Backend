<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FellowshipResource\Pages\CreateFellowship;
use App\Filament\Resources\FellowshipResource\Pages\EditFellowship;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Filament\Resources\FellowshipResource\Pages\ListFellowships;
use App\Filament\Resources\FellowshipResource\Pages\ViewFellowship;
use App\Filament\Resources\FellowshipResource\RelationManagers\FiltersRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\ImportsRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\QuestionsRelationManager;
use App\Models\Fellowship;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Fellowship question banks.
 *
 * The Fellowship is created first and its questions uploaded into it
 * afterwards: the questions table, the filters, the import wizard and the import
 * history all live on the Fellowship's own View page, so an import always knows
 * which Fellowship it belongs to without an admin ever typing an id.
 */
class FellowshipResource extends Resource
{
    protected static ?string $model = Fellowship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('admin.fellowship.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.fellowship.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.fellowship.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.learning.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.fellowship.section_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fellowship.field_name'))
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('admin.fellowship.field_is_active'))
                            ->helperText(__('admin.fellowship.is_active_hint'))
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make(__('admin.fellowship.section_image'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('fellowship_image')
                            ->label(__('admin.fellowship.field_image'))
                            ->collection(Fellowship::IMAGE_COLLECTION)
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
                SpatieMediaLibraryImageColumn::make('fellowship_image')
                    ->label(__('admin.fellowship.field_image'))
                    ->collection(Fellowship::IMAGE_COLLECTION)
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->label(__('admin.fellowship.field_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('questions_count')
                    ->label(__('admin.fellowship.field_questions_count'))
                    ->counts('questions')
                    ->badge()
                    ->sortable(),
                TextColumn::make('performanceFilter.name')
                    ->label(__('admin.fellowship.field_performance_filter'))
                    ->placeholder(__('admin.fellowship.performance_filter_unset'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('filters_count')
                    ->label(__('admin.fellowship.field_filters_count'))
                    ->counts('filters')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label(__('admin.fellowship.field_is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.fellowship.field_is_active')),
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

    public static function getRelations(): array
    {
        return [
            'questions' => QuestionsRelationManager::class,
            'filters' => FiltersRelationManager::class,
            'imports' => ImportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFellowships::route('/'),
            'create' => CreateFellowship::route('/create'),
            'view' => ViewFellowship::route('/{record}'),
            'edit' => EditFellowship::route('/{record}/edit'),
            // The wizard is a page of the record, so the Fellowship it imports
            // into is the one in the URL and never a field on a form.
            'import-questions' => ImportFellowshipQuestions::route('/{record}/import-questions'),
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
