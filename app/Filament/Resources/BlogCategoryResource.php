<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\BlogCategoryResource\Pages\CreateBlogCategory;
use App\Filament\Resources\BlogCategoryResource\Pages\EditBlogCategory;
use App\Filament\Resources\BlogCategoryResource\Pages\ListBlogCategories;
use App\Models\BlogCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BlogCategoryResource extends Resource
{
    protected static ?string $model = BlogCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.blog_categories_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.blog_categories_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.blog_categories_plural_model_label');
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
                                TextInput::make('name.en')
                                    ->label(__('admin.cms.field_name_en'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('description.en')
                                    ->label(__('admin.cms.field_description_en'))
                                    ->maxLength(500),
                            ]),
                        Tab::make(__('admin.cms.tab_arabic'))
                            ->schema([
                                TextInput::make('name.ar')
                                    ->label(__('admin.cms.field_name_ar'))
                                    ->maxLength(255),
                                TextInput::make('description.ar')
                                    ->label(__('admin.cms.field_description_ar'))
                                    ->maxLength(500),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make()
                    ->schema([
                        TextInput::make('slug')
                            ->label(__('admin.cms.field_slug'))
                            ->required()
                            ->unique(BlogCategory::class, 'slug', ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('admin.cms.field_status'))
                            ->options([
                                ContentStatus::Draft->value => __('admin.cms.status_draft'),
                                ContentStatus::Published->value => __('admin.cms.status_published'),
                                ContentStatus::Archived->value => __('admin.cms.status_archived'),
                            ])
                            ->required()
                            ->default(ContentStatus::Published->value),
                        TextInput::make('sort_order')
                            ->label(__('admin.cms.field_sort_order'))
                            ->integer()
                            ->default(0),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.cms.field_name_en'))
                    ->getStateUsing(fn (BlogCategory $record): string => $record->getTranslation('name', 'en') ?: $record->getTranslation('name', 'ar'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('name->en', 'like', "%{$search}%")->orWhere('name->ar', 'like', "%{$search}%"))
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('admin.cms.field_slug'))
                    ->searchable(),
                TextColumn::make('blogs_count')
                    ->label('Posts')
                    ->counts('blogs')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin.cms.field_sort_order'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => match ($state) {
                        ContentStatus::Published => 'success',
                        ContentStatus::Draft => 'warning',
                        ContentStatus::Archived => 'gray',
                    }),
            ])
            ->filters([
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
            ->defaultSort('sort_order');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlogCategories::route('/'),
            'create' => CreateBlogCategory::route('/create'),
            'edit' => EditBlogCategory::route('/{record}/edit'),
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
