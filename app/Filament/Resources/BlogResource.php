<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\BlogResource\Pages\CreateBlog;
use App\Filament\Resources\BlogResource\Pages\EditBlog;
use App\Filament\Resources\BlogResource\Pages\ListBlogs;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Support\Filament\Concerns\GuardsContentPublishing;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BlogResource extends Resource
{
    use GuardsContentPublishing;

    protected static ?string $model = Blog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.blogs_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.blogs_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.blogs_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_content');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Locales')
                    ->tabs([
                        Tab::make(__('admin.cms.tab_english'))
                            ->schema([
                                TextInput::make('title.en')
                                    ->label(__('admin.cms.field_title_en'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('excerpt.en')
                                    ->label(__('admin.cms.field_excerpt_en'))
                                    ->maxLength(500),
                                RichEditor::make('content.en')
                                    ->label(__('admin.cms.field_content_en'))
                                    ->columnSpanFull(),
                                TextInput::make('meta_title.en')
                                    ->label(__('admin.cms.field_meta_title_en'))
                                    ->maxLength(255),
                                TextInput::make('meta_description.en')
                                    ->label(__('admin.cms.field_meta_description_en'))
                                    ->maxLength(500),
                            ]),
                        Tab::make(__('admin.cms.tab_arabic'))
                            ->schema([
                                TextInput::make('title.ar')
                                    ->label(__('admin.cms.field_title_ar'))
                                    ->maxLength(255),
                                TextInput::make('excerpt.ar')
                                    ->label(__('admin.cms.field_excerpt_ar'))
                                    ->maxLength(500),
                                RichEditor::make('content.ar')
                                    ->label(__('admin.cms.field_content_ar'))
                                    ->columnSpanFull(),
                                TextInput::make('meta_title.ar')
                                    ->label(__('admin.cms.field_meta_title_ar'))
                                    ->maxLength(255),
                                TextInput::make('meta_description.ar')
                                    ->label(__('admin.cms.field_meta_description_ar'))
                                    ->maxLength(500),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make(__('admin.cms.section_cover_image'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('cover')
                            ->label(__('admin.cms.field_cover'))
                            ->collection('cover')
                            ->image()
                            ->acceptedFileTypes(config('media.image_mime_types', []))
                            ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.cms.section_publish'))
                    ->schema([
                        Select::make('blog_category_id')
                            ->label(__('admin.cms.field_category'))
                            ->options(BlogCategory::query()->whereNotNull('name')->pluck('name', 'id')->map(fn ($name) => is_array($name) ? ($name['en'] ?? $name['ar'] ?? '') : $name))
                            ->nullable()
                            ->searchable(),
                        TextInput::make('slug')
                            ->label(__('admin.cms.field_slug'))
                            ->required()
                            ->unique(Blog::class, 'slug', ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('admin.cms.field_status'))
                            ->options(fn (?Blog $record): array => self::publishableStatusOptions($record))
                            ->disabled(fn (?Blog $record): bool => self::isStatusLocked($record))
                            ->dehydrated(true)
                            ->helperText(fn (?Blog $record): ?string => self::statusLockedHint($record))
                            ->required()
                            ->default(ContentStatus::Draft->value),
                        DateTimePicker::make('published_at')
                            ->label(__('admin.cms.field_published_at'))
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('cover')
                    ->label(__('admin.cms.field_cover'))
                    ->collection('cover')
                    ->square()
                    ->size(48),
                TextColumn::make('title')
                    ->label(__('admin.cms.field_title_en'))
                    ->getStateUsing(fn (Blog $record): string => $record->getTranslation('title', 'en') ?: $record->getTranslation('title', 'ar'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('title->en', 'like', "%{$search}%")->orWhere('title->ar', 'like', "%{$search}%"))
                    ->limit(60),
                TextColumn::make('category.name')
                    ->label(__('admin.cms.field_category'))
                    ->getStateUsing(fn (Blog $record): string => $record->category?->getTranslation('name', 'en') ?? '-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => match ($state) {
                        ContentStatus::Published => 'success',
                        ContentStatus::Draft => 'warning',
                        ContentStatus::Archived => 'gray',
                    }),
                TextColumn::make('published_at')
                    ->label(__('admin.cms.field_published_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('blog_category_id')
                    ->label(__('admin.cms.field_category'))
                    ->relationship('category', 'name'),
                SelectFilter::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->options([
                        ContentStatus::Draft->value => __('admin.cms.status_draft'),
                        ContentStatus::Published->value => __('admin.cms.status_published'),
                        ContentStatus::Archived->value => __('admin.cms.status_archived'),
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogs::route('/'),
            'create' => CreateBlog::route('/create'),
            'edit' => EditBlog::route('/{record}/edit'),
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
