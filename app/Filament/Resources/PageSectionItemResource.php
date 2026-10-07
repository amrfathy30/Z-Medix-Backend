<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\PageSectionItemResource\Pages\CreatePageSectionItem;
use App\Filament\Resources\PageSectionItemResource\Pages\EditPageSectionItem;
use App\Filament\Resources\PageSectionItemResource\Pages\ListPageSectionItems;
use App\Models\PageSection;
use App\Models\PageSectionItem;
use App\Support\Filament\Concerns\GuardsContentPublishing;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
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

class PageSectionItemResource extends Resource
{
    use GuardsContentPublishing;

    protected static ?string $model = PageSectionItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.page_section_items_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.page_section_items_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.page_section_items_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_content');
    }

    /** @return array<int, string> */
    protected static function sectionOptions(): array
    {
        return PageSection::query()
            ->with('page')
            ->get()
            ->mapWithKeys(fn (PageSection $section): array => [
                $section->id => ($section->page?->label ?? $section->page_id).' — '.$section->label,
            ])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('page_section_id')
                    ->label(__('admin.cms.field_page_section'))
                    ->options(fn (): array => self::sectionOptions())
                    ->required()->searchable()->columnSpan(2),
                Select::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->options(fn (?PageSectionItem $record): array => self::publishableStatusOptions($record))
                    ->disabled(fn (?PageSectionItem $record): bool => self::isStatusLocked($record))
                    ->dehydrated(true)
                    ->helperText(fn (?PageSectionItem $record): ?string => self::statusLockedHint($record))
                    ->required()
                    ->default(fn (): string => self::defaultPublishableStatus(ContentStatus::Published)),
                TextInput::make('sort_order')->label(__('admin.cms.field_sort_order'))->integer()->default(0),
                TextInput::make('icon')->label(__('admin.cms.field_icon'))->maxLength(100),
                TextInput::make('link')->label(__('admin.cms.field_link'))->url()->maxLength(255),
                SpatieMediaLibraryFileUpload::make('image')
                    ->label(__('admin.cms.field_image'))
                    ->collection('image')->image()
                    ->acceptedFileTypes(config('media.image_mime_types', []))
                    ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                    ->columnSpan(2),
            ])->columns(4),
            Tabs::make('Locales')->tabs([
                Tab::make(__('admin.cms.tab_english'))->schema([
                    TextInput::make('title.en')->label(__('admin.cms.field_title_en'))->maxLength(255),
                    Textarea::make('description.en')->label(__('admin.cms.field_description_en'))->rows(4)->maxLength(2000),
                ]),
                Tab::make(__('admin.cms.tab_arabic'))->schema([
                    TextInput::make('title.ar')->label(__('admin.cms.field_title_ar'))->maxLength(255),
                    Textarea::make('description.ar')->label(__('admin.cms.field_description_ar'))->rows(4)->maxLength(2000),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('image')
                    ->label(__('admin.cms.field_image'))->collection('image')->square()->size(48),
                TextColumn::make('title')
                    ->label(__('admin.cms.field_title_en'))
                    ->getStateUsing(fn (PageSectionItem $record): string => $record->getTranslation('title', 'en') ?: $record->getTranslation('title', 'ar') ?: '-')
                    ->limit(60),
                TextColumn::make('pageSection.label')
                    ->label(__('admin.cms.field_page_section'))
                    ->getStateUsing(fn (PageSectionItem $record): string => ($record->pageSection?->page?->label ?? '').' — '.($record->pageSection?->label ?? ''))
                    ->sortable(),
                TextColumn::make('sort_order')->label(__('admin.cms.field_sort_order'))->sortable(),
                TextColumn::make('status')->label(__('admin.cms.field_status'))->badge()
                    ->color(fn (ContentStatus $state): string => match ($state) {
                        ContentStatus::Published => 'success',
                        ContentStatus::Draft => 'warning',
                        ContentStatus::Archived => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('page_section_id')->label(__('admin.cms.field_page_section'))
                    ->options(fn (): array => self::sectionOptions()),
                SelectFilter::make('status')->label(__('admin.cms.field_status'))->options([
                    ContentStatus::Draft->value => __('admin.cms.status_draft'),
                    ContentStatus::Published->value => __('admin.cms.status_published'),
                    ContentStatus::Archived->value => __('admin.cms.status_archived'),
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make(), ForceDeleteBulkAction::make(), RestoreBulkAction::make()]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPageSectionItems::route('/'),
            'create' => CreatePageSectionItem::route('/create'),
            'edit' => EditPageSectionItem::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
