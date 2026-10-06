<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages\EditQuestion;
use App\Filament\Resources\QuestionResource\Pages\ViewQuestion;
use App\Models\Question;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A question is reached through its quiz, never from the sidebar. Its options
 * are authored inline with a repeater, which also enforces the single-answer
 * rule the quiz engine depends on.
 */
class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $recordTitleAttribute = 'question';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('admin.learning.questions_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learning.questions_plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.question_section_details'))
                    ->schema([
                        self::questionField()->columnSpanFull(),
                    ]),

                Section::make(__('admin.learning.question_section_options'))
                    ->schema([
                        self::optionsRepeater(),
                    ]),
            ]);
    }

    public static function questionField(): Textarea
    {
        return Textarea::make('question')
            ->label(__('admin.learning.field_question_text'))
            ->required()
            ->rows(3)
            ->maxLength(2000);
    }

    /**
     * Answer options: at least two, exactly one marked correct, order preserved.
     */
    public static function optionsRepeater(): Repeater
    {
        return Repeater::make('options')
            ->label(__('admin.learning.question_section_options'))
            ->relationship()
            ->orderColumn('order')
            ->reorderable()
            ->minItems(2)
            ->defaultItems(2)
            ->helperText(__('admin.learning.options_helper'))
            ->schema([
                TextInput::make('option_text')
                    ->label(__('admin.learning.field_option_text'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),
                Toggle::make('is_correct')
                    ->label(__('admin.learning.field_option_is_correct'))
                    ->inline(false)
                    ->default(false),
            ])
            ->columns(3)
            ->rule(static function (): Closure {
                return static function (string $attribute, mixed $value, Closure $fail): void {
                    $options = is_array($value) ? $value : [];

                    if (count($options) < 2) {
                        $fail(__('admin.learning.options_min_items'));

                        return;
                    }

                    $correctCount = collect($options)
                        ->filter(fn (mixed $option): bool => filter_var(
                            is_array($option) ? ($option['is_correct'] ?? false) : false,
                            FILTER_VALIDATE_BOOLEAN,
                        ))
                        ->count();

                    if ($correctCount !== 1) {
                        $fail(__('admin.learning.options_exactly_one_correct'));
                    }
                };
            })
            ->columnSpanFull();
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewQuestion::route('/{record}'),
            'edit' => EditQuestion::route('/{record}/edit'),
        ];
    }
}
