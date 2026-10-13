<?php

namespace App\Services\Fellowship;

use App\Enums\QuizDifficulty;
use App\Support\Fellowship\QuestionTextNormalizer;

/**
 * Reads a spreadsheet's difficulty cell.
 *
 * The column name is the admin's to choose, but the three levels are the
 * system's: Easy, Medium and Hard. Accepting the obvious spellings of each —
 * case, spacing, and the single-letter and numeric shorthands people type into
 * spreadsheets — means a file is rejected for saying something genuinely
 * unrecognised, not for saying "EASY".
 */
final class DifficultyParser
{
    /**
     * @var array<string, QuizDifficulty>
     */
    private const ALIASES = [
        'easy' => QuizDifficulty::Easy,
        'e' => QuizDifficulty::Easy,
        '1' => QuizDifficulty::Easy,
        'simple' => QuizDifficulty::Easy,
        'beginner' => QuizDifficulty::Easy,

        'medium' => QuizDifficulty::Medium,
        'm' => QuizDifficulty::Medium,
        '2' => QuizDifficulty::Medium,
        'moderate' => QuizDifficulty::Medium,
        'intermediate' => QuizDifficulty::Medium,

        'hard' => QuizDifficulty::Hard,
        'h' => QuizDifficulty::Hard,
        '3' => QuizDifficulty::Hard,
        'difficult' => QuizDifficulty::Hard,
        'advanced' => QuizDifficulty::Hard,
    ];

    public static function parse(?string $value): ?QuizDifficulty
    {
        if ($value === null) {
            return null;
        }

        return self::ALIASES[QuestionTextNormalizer::normalize($value)] ?? null;
    }

    /**
     * The levels as the import error message lists them.
     */
    public static function allowedLabels(): string
    {
        return 'Easy, Medium, Hard';
    }
}
