<?php

namespace App\Filament\Resources;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\Pages\ViewQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Models\Quiz;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * A quiz is reached through its chapter, never from the sidebar.
 */
class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('admin.learning.quizzes_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learning.quizzes_plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.quiz_section_details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('admin.learning.field_quiz_title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('difficulty')
                            ->label(__('admin.learning.field_quiz_difficulty'))
                            ->options(self::difficultyOptions())
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /** @return array<string, string> */
    public static function difficultyOptions(): array
    {
        return [
            QuizDifficulty::Easy->value => __('admin.learning.difficulty_easy'),
            QuizDifficulty::Medium->value => __('admin.learning.difficulty_medium'),
            QuizDifficulty::Hard->value => __('admin.learning.difficulty_hard'),
        ];
    }

    public static function difficultyLabel(QuizDifficulty $difficulty): string
    {
        return self::difficultyOptions()[$difficulty->value];
    }

    public static function difficultyColor(QuizDifficulty $difficulty): string
    {
        return match ($difficulty) {
            QuizDifficulty::Easy => 'success',
            QuizDifficulty::Medium => 'warning',
            QuizDifficulty::Hard => 'danger',
        };
    }

    public static function getRelations(): array
    {
        return [
            'questions' => QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewQuiz::route('/{record}'),
            'edit' => EditQuiz::route('/{record}/edit'),
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
