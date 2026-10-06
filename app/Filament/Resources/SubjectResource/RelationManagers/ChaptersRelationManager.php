<?php

namespace App\Filament\Resources\SubjectResource\RelationManagers;

use App\Enums\ContentStatus;
use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Chapter;
use App\Models\Subject;
use App\Services\Learning\ChapterService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Chapters of the subject being viewed. Creating from here assigns subject_id
 * from the owner record and writes the chapter together with its quiz in one
 * transaction, since every chapter must own exactly one quiz.
 */
class ChaptersRelationManager extends RelationManager
{
    protected static string $relationship = 'chapters';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learning.chapters_section');
    }

    /**
     * The owner's View page is the workspace for managing these records, so the
     * table stays writable there; the model policies still decide who may act.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A new chapter is always a draft and is appended to the end of
                // the subject, so neither status nor order is asked for here.
                Section::make(__('admin.learning.chapter_section_details'))
                    ->description(__('admin.learning.chapter_draft_on_create_hint'))
                    ->schema([
                        ChapterResource::titleField()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('quiz.title'))) {
                                    $set('quiz.title', ChapterService::defaultQuizTitle((string) $state));
                                }
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.learning.quiz_section'))
                    ->description(__('admin.learning.quiz_section_create_with_chapter_hint'))
                    ->schema([
                        TextInput::make('quiz.title')
                            ->label(__('admin.learning.field_quiz_title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('quiz.difficulty')
                            ->label(__('admin.learning.field_quiz_difficulty'))
                            ->options(QuizResource::difficultyOptions())
                            ->required()
                            ->default(null),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.learning.field_chapter_title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order')
                    ->label(__('admin.learning.field_chapter_order'))
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->tooltip(__('admin.learning.reorder_hint')),
                TextColumn::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => SubjectResource::statusColor($state))
                    ->formatStateUsing(fn (ContentStatus $state): string => SubjectResource::statusLabel($state)),
                TextColumn::make('pages_count')
                    ->label(__('admin.learning.field_pages_count'))
                    ->counts('pages')
                    ->badge(),
                TextColumn::make('quiz.title')
                    ->label(__('admin.learning.field_has_quiz'))
                    ->placeholder('—'),
                IconColumn::make('quiz_has_questions')
                    ->label(__('admin.learning.field_questions_count'))
                    ->state(fn (Chapter $record): bool => $record->isPublishable())
                    ->boolean()
                    ->tooltip(fn (Chapter $record): ?string => $record->isPublishable()
                        ? null
                        : __('admin.learning.quiz_no_questions_warning')),
                TextColumn::make('created_at')
                    ->label(__('admin.learning.field_created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.learning.field_status'))
                    ->options(SubjectResource::statusOptions()),
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data, ChapterService $chapters): Chapter {
                        /** @var Subject $subject */
                        $subject = $this->getOwnerRecord();

                        return $chapters->createWithQuiz(
                            $subject,
                            ['title' => $data['title'] ?? null],
                            $data['quiz'] ?? [],
                        );
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('admin.learning.action_view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Chapter $record): string => ChapterResource::getUrl('view', ['record' => $record]))
                    ->authorize(fn (Chapter $record): bool => ChapterResource::canView($record)),
                Action::make('edit')
                    ->label(__('admin.learning.action_edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Chapter $record): string => ChapterResource::getUrl('edit', ['record' => $record]))
                    ->authorize(fn (Chapter $record): bool => ChapterResource::canEdit($record)),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                // Each record is fetched and deleted/restored individually so the
                // model events that carry the quiz along always fire — a bulk
                // query delete would skip them and orphan the quiz.
                BulkActionGroup::make([
                    DeleteBulkAction::make()->fetchSelectedRecords(),
                    ForceDeleteBulkAction::make()->fetchSelectedRecords(),
                    RestoreBulkAction::make()->fetchSelectedRecords(),
                ]),
            ])
            ->reorderable('order')
            ->defaultSort('order');
    }
}
