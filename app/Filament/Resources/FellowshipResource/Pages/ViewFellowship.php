<?php

namespace App\Filament\Resources\FellowshipResource\Pages;

use App\Filament\Resources\FellowshipResource;
use App\Filament\Resources\FellowshipResource\RelationManagers\FiltersRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\ImportsRelationManager;
use App\Filament\Resources\FellowshipResource\RelationManagers\QuestionsRelationManager;
use App\Models\Fellowship;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The admin workspace for one Fellowship: an overview, the Import Questions
 * action, and a tabbed workspace holding the Questions, Filters and Import
 * History tables.
 *
 * Mirrors ViewSubject's shape, including replacing Filament's own
 * relation-manager tab strip so the three tables sit side by side rather than
 * stacking down the page.
 */
class ViewFellowship extends ViewRecord
{
    protected static string $resource = FellowshipResource::class;

    public const TAB_QUESTIONS = 'questions';

    public const TAB_FILTERS = 'filters';

    public const TAB_IMPORTS = 'imports';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importQuestions')
                ->label(__('admin.fellowship.action_import_questions'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(fn (Fellowship $record): string => FellowshipResource::getUrl('import-questions', ['record' => $record]))
                ->visible(fn (): bool => ImportFellowshipQuestions::canImport()),
            EditAction::make(),
        ];
    }

    /**
     * Filament resets `activeRelationManager` to a known key on every render,
     * so the set of valid keys is widened here rather than fought with.
     */
    public function renderingHasRelationManagers(): void
    {
        if (! in_array($this->activeRelationManager, self::tabKeys(), true)) {
            $this->activeRelationManager = self::TAB_QUESTIONS;
        }
    }

    /** @return array<int, string> */
    public static function tabKeys(): array
    {
        return [self::TAB_QUESTIONS, self::TAB_FILTERS, self::TAB_IMPORTS];
    }

    public function getRelationManagersContentComponent(): Component
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        return Tabs::make()
            ->key('relationManagerTabs')
            ->livewireProperty('activeRelationManager')
            ->contained(false)
            ->tabs([
                self::TAB_QUESTIONS => Tab::make(__('admin.fellowship.questions_section'))
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->badge($fellowship->questions()->count())
                    ->schema([
                        Livewire::make(QuestionsRelationManager::class, [
                            'ownerRecord' => $fellowship,
                            'pageClass' => static::class,
                        ])->key(QuestionsRelationManager::class),
                    ]),

                self::TAB_FILTERS => Tab::make(__('admin.fellowship.filters_section'))
                    ->icon(Heroicon::OutlinedFunnel)
                    ->badge($fellowship->filters()->count())
                    ->schema([
                        Livewire::make(FiltersRelationManager::class, [
                            'ownerRecord' => $fellowship,
                            'pageClass' => static::class,
                        ])->key(FiltersRelationManager::class),
                    ]),

                self::TAB_IMPORTS => Tab::make(__('admin.fellowship.imports_section'))
                    ->icon(Heroicon::OutlinedClock)
                    ->badge($fellowship->imports()->count())
                    ->schema([
                        Livewire::make(ImportsRelationManager::class, [
                            'ownerRecord' => $fellowship,
                            'pageClass' => static::class,
                        ])->key(ImportsRelationManager::class),
                    ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Until an import designates one, the Fellowship has no
                // Performance Topic classification — and without it the student
                // cards, Topics Completed and Topic Performance have nothing to
                // report.
                Callout::make(__('admin.fellowship.performance_filter_missing_heading'))
                    ->key('fellowship-performance-filter-warning')
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(__('admin.fellowship.performance_filter_missing_description'))
                    ->visible(fn (Fellowship $record): bool => $record->performance_filter_id === null),

                Section::make(__('admin.fellowship.section_overview'))
                    ->key('fellowship-overview')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('fellowship_image')
                            ->label(__('admin.fellowship.field_image'))
                            ->collection(Fellowship::IMAGE_COLLECTION)
                            ->square()
                            ->height(96),
                        TextEntry::make('name')
                            ->label(__('admin.fellowship.field_name')),
                        TextEntry::make('is_active')
                            ->label(__('admin.fellowship.field_is_active'))
                            ->badge()
                            ->color(fn (Fellowship $record): string => $record->is_active ? 'success' : 'gray')
                            ->state(fn (Fellowship $record): string => $record->is_active
                                ? __('admin.fellowship.status_active')
                                : __('admin.fellowship.status_inactive')),
                        TextEntry::make('questions_count')
                            ->label(__('admin.fellowship.field_questions_count'))
                            ->state(fn (Fellowship $record): int => $record->questions()->count())
                            ->badge(),
                        TextEntry::make('performance_filter')
                            ->label(__('admin.fellowship.field_performance_filter'))
                            ->state(fn (Fellowship $record): ?string => $record->performanceFilter?->name)
                            ->placeholder(__('admin.fellowship.performance_filter_unset'))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('topics_count')
                            ->label(__('admin.fellowship.field_topics_count'))
                            ->state(fn (Fellowship $record): int => $record->performanceFilter?->values()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('created_at')
                            ->label(__('admin.learning.field_created_at'))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label(__('admin.learning.field_updated_at'))
                            ->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }
}
