<?php

namespace App\Enums;

/**
 * What one uploaded spreadsheet header is mapped onto.
 *
 * Column names are never hardcoded: the admin decides, per header, whether it
 * carries a system field, feeds an existing dynamic filter, creates a new one,
 * or is ignored. The Fellowship itself is never mapped — it is resolved from
 * the record the wizard was opened on.
 */
enum FellowshipImportMappingTarget: string
{
    case QuestionText = 'question_text';
    case Explanation = 'explanation';
    case Difficulty = 'difficulty';
    case CorrectAnswer = 'correct_answer';
    case AnswerOption = 'answer_option';
    case PerformanceTopic = 'performance_topic';
    case ExistingFilter = 'existing_filter';
    case NewFilter = 'new_filter';
    case Ignore = 'ignore';

    /**
     * Targets that must each be mapped at least once before confirmation is
     * allowed. Answer options additionally need two mapped columns, which the
     * mapping object checks separately since the count, not the presence, is
     * what matters there.
     *
     * @return list<self>
     */
    public static function required(): array
    {
        return [
            self::QuestionText,
            self::Difficulty,
            self::CorrectAnswer,
            self::PerformanceTopic,
            self::AnswerOption,
        ];
    }

    /**
     * The single-value system fields, in the order the mapping step presents
     * them: the required ones first, then the optional.
     *
     * Answer options are deliberately absent — they are the one repeatable
     * system field and the mapping step gives them their own numbered slots.
     *
     * @return list<self>
     */
    public static function systemFields(): array
    {
        return [
            self::QuestionText,
            self::CorrectAnswer,
            self::Difficulty,
            self::PerformanceTopic,
            self::Explanation,
        ];
    }

    /**
     * Whether the import refuses to proceed until this target has a column.
     */
    public function isRequired(): bool
    {
        return in_array($this, self::required(), true);
    }

    /**
     * Targets that may be mapped more than once in a single import.
     */
    public function isRepeatable(): bool
    {
        return match ($this) {
            self::AnswerOption, self::ExistingFilter, self::NewFilter, self::Ignore => true,
            default => false,
        };
    }

    /**
     * True for the two dynamic-filter targets, whose mapping carries a filter
     * name or id alongside the target itself.
     */
    public function isDynamicFilter(): bool
    {
        return $this === self::ExistingFilter || $this === self::NewFilter;
    }
}
