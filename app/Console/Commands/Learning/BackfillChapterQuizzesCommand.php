<?php

namespace App\Console\Commands\Learning;

use App\Models\Chapter;
use App\Services\Learning\ChapterService;
use Illuminate\Console\Command;

/**
 * Creates the missing quiz for chapters that predate the "every chapter owns one
 * quiz" rule. Idempotent and additive: it only ever inserts quizzes for chapters
 * that have none (live or trashed), and never runs automatically.
 */
class BackfillChapterQuizzesCommand extends Command
{
    protected $signature = 'learning:backfill-chapter-quizzes {--dry-run : Report what would change without writing}';

    protected $description = 'Create the missing quiz for any chapter that does not have one';

    public function handle(ChapterService $chapters): int
    {
        $query = Chapter::query()
            ->withTrashed()
            ->whereDoesntHave('quiz', fn ($quiz) => $quiz->withTrashed());

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to do: every chapter already has a quiz.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("{$count} chapter(s) have no quiz and would receive one:");

            (clone $query)->each(function (Chapter $chapter): void {
                $this->line("  #{$chapter->getKey()} {$chapter->title} → \"".ChapterService::defaultQuizTitle((string) $chapter->title).'"');
            });

            return self::SUCCESS;
        }

        $created = 0;

        $query->each(function (Chapter $chapter) use ($chapters, &$created): void {
            if ($chapters->backfillMissingQuiz($chapter) !== null) {
                $created++;
            }
        });

        $this->info("Created {$created} missing quiz/quizzes.");

        return self::SUCCESS;
    }
}
