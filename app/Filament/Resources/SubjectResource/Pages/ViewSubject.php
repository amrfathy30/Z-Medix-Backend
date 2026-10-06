<?php

namespace App\Filament\Resources\SubjectResource\Pages;

use App\Enums\ContentStatus;
use App\Filament\Resources\SubjectResource;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Subject;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The primary admin workspace for a subject: an overview, then a tabbed
 * workspace holding the Chapters and Books tables, plus Students Progress,
 * whose module is not built yet.
 *
 * The tabs replace Filament's own relation-manager tab strip so the live
 * Chapters and Books tables and the Students Progress placeholder sit side by
 * side instead of stacking down the page.
 */
class ViewSubject extends ViewRecord
{
    protected static string $resource = SubjectResource::class;

    public const TAB_CHAPTERS = 'chapters';

    public const TAB_BOOKS = 'books';

    public const TAB_STUDENTS_PROGRESS = 'students-progress';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    /**
     * Filament resets `activeRelationManager` to a known relation manager key on
     * every render; the Students Progress placeholder tab is not a relation
     * manager, so the set of valid keys is widened here instead.
     */
    public function renderingHasRelationManagers(): void
    {
        if (! in_array($this->activeRelationManager, self::tabKeys(), true)) {
            $this->activeRelationManager = self::TAB_CHAPTERS;
        }
    }

    /** @return array<int, string> */
    public static function tabKeys(): array
    {
        return [self::TAB_CHAPTERS, self::TAB_BOOKS, self::TAB_STUDENTS_PROGRESS];
    }

    public function getRelationManagersContentComponent(): Component
    {
        /** @var Subject $subject */
        $subject = $this->getRecord();

        return Tabs::make()
            ->key('relationManagerTabs')
            ->livewireProperty('activeRelationManager')
            ->contained(false)
            ->tabs([
                self::TAB_CHAPTERS => Tab::make(__('admin.learning.chapters_section'))
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->badge($subject->chapters()->count())
                    ->schema([
                        Livewire::make(ChaptersRelationManager::class, [
                            'ownerRecord' => $subject,
                            'pageClass' => static::class,
                        ])->key(ChaptersRelationManager::class),
                    ]),

                self::TAB_BOOKS => Tab::make(__('admin.learning.books_section'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->badge($subject->books()->count())
                    ->schema([
                        Livewire::make(BooksRelationManager::class, [
                            'ownerRecord' => $subject,
                            'pageClass' => static::class,
                        ])->key(BooksRelationManager::class),
                    ]),

                self::TAB_STUDENTS_PROGRESS => Tab::make(__('admin.learning.students_progress_section'))
                    ->icon(Heroicon::OutlinedChartBar)
                    ->schema([
                        EmptyState::make(__('admin.learning.students_progress_placeholder_heading'))
                            ->key('students-progress-placeholder')
                            ->description(__('admin.learning.students_progress_placeholder_description'))
                            ->icon(Heroicon::OutlinedChartBar),
                    ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.subject_section_overview'))
                    ->key('subject-overview')
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('subject_image')
                            ->label(__('admin.learning.field_subject_image'))
                            ->collection(Subject::IMAGE_COLLECTION)
                            ->square()
                            ->height(96),
                        TextEntry::make('name')
                            ->label(__('admin.learning.field_subject_name')),
                        TextEntry::make('status')
                            ->label(__('admin.learning.field_status'))
                            ->badge()
                            ->color(fn (ContentStatus $state): string => SubjectResource::statusColor($state))
                            ->formatStateUsing(fn (ContentStatus $state): string => SubjectResource::statusLabel($state)),
                        TextEntry::make('chapters_count')
                            ->label(__('admin.learning.field_chapters_count'))
                            ->state(fn (Subject $record): int => $record->chapters()->count())
                            ->badge(),
                        TextEntry::make('books_count')
                            ->label(__('admin.learning.books_section'))
                            ->state(fn (Subject $record): int => $record->books()->count())
                            ->badge(),
                        TextEntry::make('students_count')
                            ->label(__('admin.learning.field_students_count'))
                            ->state(fn (Subject $record): int => $record->studentsCount())
                            ->helperText(__('admin.learning.students_count_placeholder_hint'))
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
