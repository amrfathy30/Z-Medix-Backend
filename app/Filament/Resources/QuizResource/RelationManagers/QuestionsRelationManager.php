<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Filament\Resources\QuestionResource;
use App\Models\Question;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Questions of the quiz being viewed. Creating from here assigns quiz_id through
 * the relationship.
 *
 * A quiz must never lose its last question, so the delete action disappears once
 * only one is left. The binding rule lives on the Question model, which rejects
 * the delete even if this button's visibility is bypassed. There is no bulk
 * delete, since a bulk query delete would skip that guard entirely.
 *
 * Question order is set by dragging rows, never typed in.
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.learning.questions_section');
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
                QuestionResource::questionField()->columnSpanFull(),
                QuestionResource::optionsRepeater(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->columns([
                TextColumn::make('order')
                    ->label(__('admin.learning.field_question_order'))
                    ->badge()
                    ->color('gray')
                    ->tooltip(__('admin.learning.reorder_hint')),
                TextColumn::make('question')
                    ->label(__('admin.learning.field_question_text'))
                    ->searchable()
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('options_count')
                    ->label(__('admin.learning.field_options_count'))
                    ->counts('options')
                    ->badge(),
                TextColumn::make('correct_answer')
                    ->label(__('admin.learning.field_correct_answer'))
                    ->state(fn (Question $record): ?string => $record->correctOption()?->option_text)
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('admin.learning.action_view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Question $record): string => QuestionResource::getUrl('view', ['record' => $record]))
                    ->authorize(fn (Question $record): bool => QuestionResource::canView($record)),
                Action::make('edit')
                    ->label(__('admin.learning.action_edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Question $record): string => QuestionResource::getUrl('edit', ['record' => $record]))
                    ->authorize(fn (Question $record): bool => QuestionResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (Question $record): bool => $record->canBeDeleted())
                    ->tooltip(fn (Question $record): ?string => $record->canBeDeleted()
                        ? null
                        : __('admin.learning.last_question_cannot_be_deleted')),
            ])
            ->reorderable('order')
            ->defaultSort('order');
    }
}
