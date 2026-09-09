<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Filament\Resources\PageSectionResource\Pages\ListPageSections;
use App\Models\PageSection;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Forms\ContentInputFactory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PageSectionResource extends Resource
{
    protected static ?string $model = PageSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 9;

    /**
     * Always hidden from the sidebar — editing happens exclusively through
     * WebsiteContentSectionsPage, which links directly into this resource's
     * EditPageSection.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * This resource is content-editing only, never a page/section builder.
     * Which sections exist, on which page, with which order/visibility,
     * is fixed by seeders and WebsiteContentDefinitions — never by an
     * admin action. No role, including super_admin, can create, delete,
     * force-delete, or restore a PageSection from the dashboard.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.page_sections_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.page_sections_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.page_sections_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_content');
    }

    /**
     * Content-editing only: page_id, section_key, status, and sort_order are
     * never rendered, in any form, for any role. They are structural/design
     * settings controlled exclusively by seeders and
     * WebsiteContentDefinitions. Omitting these fields from the schema
     * means Filament's getState() never includes them, so saving content
     * never touches — and therefore never changes — their existing DB values.
     *
     * The schema itself is generated from the exact page key + section_key
     * definition via ContentDefinitionRegistry — there is no fallback by
     * type, and a missing definition throws MissingContentDefinitionException
     * rather than silently rendering nothing or a generic field set.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(fn (?PageSection $record): array => self::buildComponents($record));
    }

    /** @return list<Component> */
    private static function buildComponents(?PageSection $record): array
    {
        if ($record === null) {
            return [];
        }

        $page = $record->page;

        if ($page === null) {
            throw new RuntimeException("PageSection #{$record->id} has no associated Page (page_id is null) — cannot resolve its content definition.");
        }

        $registry = app(ContentDefinitionRegistry::class);
        $pageDefinition = $registry->forPage($page->key);
        $sectionDefinition = $registry->forSection($page->key, $record->section_key);

        $locale = app()->getLocale();

        $components = [
            Placeholder::make('editing_context')
                ->label('')
                ->content(__('admin.cms.page_section_editing_label', [
                    'page' => $locale === 'ar' ? $pageDefinition->labelAr : $pageDefinition->labelEn,
                    'section' => $locale === 'ar' ? $sectionDefinition->labelAr : $sectionDefinition->labelEn,
                ]))
                ->columnSpanFull(),
        ];

        $factory = app(ContentInputFactory::class);

        foreach ($sectionDefinition->orderedItems() as $item) {
            $components[] = $factory->make($item, 'data')->columnSpanFull();
        }

        return $components;
    }

    /** Content lives in data.title — falls back to an empty string if a section has none. */
    private static function displayTitle(PageSection $record): string
    {
        $dataTitle = $record->data['title'] ?? null;

        if (is_array($dataTitle)) {
            return $dataTitle['en'] ?? $dataTitle['ar'] ?? '';
        }

        return '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('page.label')
                    ->label(__('admin.cms.field_page'))
                    ->badge(),
                TextColumn::make('section_key')
                    ->label(__('admin.cms.field_section_key'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->label(__('admin.cms.field_section_label'))
                    ->searchable(),
                TextColumn::make('title')
                    ->label(__('admin.cms.field_title_en'))
                    ->getStateUsing(fn (PageSection $record): string => self::displayTitle($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('data->title->en', 'like', "%{$search}%")
                        ->orWhere('data->title->ar', 'like', "%{$search}%"))
                    ->limit(40),
                TextColumn::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => match ($state) {
                        ContentStatus::Published => 'success',
                        ContentStatus::Draft => 'warning',
                        ContentStatus::Archived => 'gray',
                    }),
                TextColumn::make('sort_order')
                    ->label(__('admin.cms.field_sort_order'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('admin.cms.field_updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('page')
                    ->label(__('admin.cms.field_page'))
                    ->relationship('page', 'label'),
                SelectFilter::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->options([
                        ContentStatus::Draft->value => __('admin.cms.status_draft'),
                        ContentStatus::Published->value => __('admin.cms.status_published'),
                        ContentStatus::Archived->value => __('admin.cms.status_archived'),
                    ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->join('pages', 'pages.id', '=', 'page_sections.page_id')
                ->orderBy('pages.key')
                ->orderBy('page_sections.sort_order')
                ->select('page_sections.*'))
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPageSections::route('/'),
            'edit' => EditPageSection::route('/{record}/edit'),
        ];
    }
}
