<?php

namespace App\Support\Fellowship;

use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * The form one Fellowship question is authored and corrected with.
 *
 * Built from the Fellowship rather than declared statically, because a
 * Fellowship's dynamic filters are discovered from its imports: the fields
 * offered here are whichever filters that Fellowship actually has.
 *
 * It enforces the same rules the importer does — at least two options, exactly
 * one correct, exactly one Performance Topic — so a question corrected by hand
 * cannot end up in a shape an imported one could not have.
 *
 * Dynamic filter values are saved by {@see SavesQuestionFilterValues}, which the
 * owning page applies after the question itself is written.
 */
final class FellowshipQuestionFormFields
{
    /**
     * Prefix the per-filter selects are named with, so they are easy to pick
     * out of the form state without colliding with a column name.
     */
    public const FILTER_FIELD_PREFIX = 'dynamic_filter_';

    /**
     * @return list<Component>
     */
    public static function for(Fellowship $fellowship): array
    {
        return [
            Section::make(__('admin.fellowship.question_section_details'))
                ->schema([
                    // A short heading beside the question, never instead of it.
                    // Optional: every question imported before this field has
                    // none, and a bank that never uses titles stays valid.
                    TextInput::make('title')
                        ->label(__('admin.fellowship.field_title'))
                        ->helperText(__('admin.fellowship.title_hint'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('question_text')
                        ->label(__('admin.fellowship.field_question_text'))
                        ->required()
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Select::make('difficulty')
                        ->label(__('admin.fellowship.field_difficulty'))
                        ->options(self::difficultyOptions())
                        ->required()
                        ->default(QuizDifficulty::Medium->value),
                    self::performanceTopicField($fellowship),
                    Textarea::make('explanation')
                        ->label(__('admin.fellowship.field_explanation'))
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('admin.fellowship.question_section_options'))
                ->schema([
                    self::optionsRepeater(),
                ]),

            Section::make(__('admin.fellowship.question_section_filters'))
                ->schema(self::dynamicFilterFields($fellowship))
                ->columns(2)
                // A Fellowship with no dynamic filters yet has nothing to show
                // here; the section appears once an import introduces one.
                ->visible(fn (): bool => $fellowship->dynamicFilters()->exists()),
        ];
    }

    /**
     * The required classification. The options are the values of the
     * Fellowship's own performance filter, and a value may be added inline for
     * a question that belongs to a topic the bank does not have yet.
     */
    public static function performanceTopicField(Fellowship $fellowship): Select
    {
        return Select::make('performance_filter_value_id')
            ->label(__('admin.fellowship.field_performance_topic'))
            ->options(fn (): array => $fellowship->performanceFilter
                ?->values()
                ->pluck('value', 'id')
                ->all() ?? [])
            ->searchable()
            ->required()
            // Never disabled: a required field the admin cannot fill is a dead
            // end, and the form is only reachable once the Fellowship has a
            // classification to choose from. The hint is kept for the moment
            // between designating the filter and adding its first value.
            ->helperText(fn (): ?string => $fellowship->performance_filter_id === null
                ? __('admin.fellowship.performance_topic_needs_filter')
                : null);
    }

    /**
     * Answer options: at least two, exactly one marked correct, order
     * preserved.
     *
     * Each option carries its own explanation beside its text and its correct
     * flag — why that answer is the right one, or why it is not. It is optional
     * per option and separate from the question's own explanation, which is
     * unchanged.
     */
    public static function optionsRepeater(): Repeater
    {
        return Repeater::make('options')
            ->label(__('admin.fellowship.question_section_options'))
            ->relationship()
            ->orderColumn('order')
            ->reorderable()
            ->minItems(2)
            ->defaultItems(2)
            ->helperText(__('admin.fellowship.options_helper'))
            ->schema([
                TextInput::make('option_text')
                    ->label(__('admin.fellowship.field_option_text'))
                    ->required()
                    ->maxLength(1000)
                    ->columnSpan(2),
                Toggle::make('is_correct')
                    ->label(__('admin.fellowship.field_option_is_correct'))
                    ->inline(false)
                    ->default(false),
                Textarea::make('explanation')
                    ->label(__('admin.fellowship.field_option_explanation'))
                    ->helperText(__('admin.fellowship.option_explanation_hint'))
                    ->rows(2)
                    ->maxLength(5000)
                    ->columnSpanFull(),
            ])
            ->columns(3)
            ->rule(static function (): Closure {
                return static function (string $attribute, mixed $value, Closure $fail): void {
                    $options = is_array($value) ? $value : [];

                    if (count($options) < 2) {
                        $fail(__('admin.fellowship.options_min_items'));

                        return;
                    }

                    $correctCount = collect($options)
                        ->filter(fn (mixed $option): bool => filter_var(
                            is_array($option) ? ($option['is_correct'] ?? false) : false,
                            FILTER_VALIDATE_BOOLEAN,
                        ))
                        ->count();

                    if ($correctCount !== 1) {
                        $fail(__('admin.fellowship.options_exactly_one_correct'));
                    }
                };
            })
            ->columnSpanFull();
    }

    /**
     * One optional select per dynamic filter the Fellowship has. A question is
     * never required to carry a value: filters introduced by a later import
     * leave earlier questions unclassified, which is expected.
     *
     * @return list<Component>
     */
    public static function dynamicFilterFields(Fellowship $fellowship): array
    {
        return $fellowship->dynamicFilters()
            ->with('values')
            ->get()
            ->map(fn (FellowshipFilter $filter): Component => Select::make(self::FILTER_FIELD_PREFIX.$filter->getKey())
                ->label($filter->name)
                ->options($filter->values->pluck('value', 'id')->all())
                ->searchable()
                ->placeholder(__('admin.fellowship.filter_value_none'))
                ->helperText(__('admin.fellowship.filter_value_optional')))
            ->values()
            ->all();
    }

    /**
     * The question's current dynamic filter values, as form state.
     *
     * @return array<string, int|null>
     */
    public static function filterStateFor(FellowshipQuestion $question): array
    {
        return $question->filterValues()
            ->get()
            ->mapWithKeys(fn ($pivot): array => [
                self::FILTER_FIELD_PREFIX.$pivot->fellowship_filter_id => $pivot->fellowship_filter_value_id,
            ])
            ->all();
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
}
