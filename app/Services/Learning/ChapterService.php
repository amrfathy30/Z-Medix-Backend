<?php

namespace App\Services\Learning;

use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\Quiz;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * The supported domain path for creating a chapter.
 *
 * Every chapter must own exactly one quiz, so the chapter and its quiz are
 * written in one transaction: if the quiz cannot be created, the chapter is
 * rolled back with it and no half-built chapter is left behind.
 *
 * A brand-new chapter is always a draft. Its quiz has no questions yet, so it
 * could not pass the publishing rule anyway; forcing the status here means a
 * crafted request cannot publish an empty chapter even if it bypasses the form.
 */
class ChapterService
{
    /**
     * @param  array<string, mixed>  $chapterAttributes  title, and optionally order; any status is ignored
     * @param  array<string, mixed>  $quizAttributes  title and difficulty
     */
    public function createWithQuiz(Subject $subject, array $chapterAttributes, array $quizAttributes): Chapter
    {
        return DB::transaction(function () use ($subject, $chapterAttributes, $quizAttributes): Chapter {
            /** @var Chapter $chapter */
            $chapter = $subject->chapters()->create([
                ...$chapterAttributes,
                'status' => ContentStatus::Draft,
            ]);

            $chapter->quiz()->create([
                'title' => filled($quizAttributes['title'] ?? null)
                    ? $quizAttributes['title']
                    : self::defaultQuizTitle((string) $chapter->title),
                'difficulty' => $quizAttributes['difficulty'] ?? null,
            ]);

            return $chapter->load('quiz');
        });
    }

    /**
     * Create the quiz a chapter should already own. Used by the backfill command
     * for chapters that predate the "every chapter has a quiz" rule.
     */
    public function backfillMissingQuiz(Chapter $chapter): ?Quiz
    {
        if ($chapter->quiz || $chapter->trashedQuiz()) {
            return null;
        }

        return $chapter->quiz()->create([
            'title' => self::defaultQuizTitle((string) $chapter->title),
            'difficulty' => Quiz::DEFAULT_DIFFICULTY,
        ]);
    }

    public static function defaultQuizTitle(string $chapterTitle): string
    {
        return trim($chapterTitle).' Quiz';
    }
}
