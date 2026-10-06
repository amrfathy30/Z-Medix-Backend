<?php

namespace Tests\Feature\Learning;

use App\Enums\QuizDifficulty;
use App\Models\Chapter;
use App\Models\Quiz;
use App\Services\Learning\ChapterService;

class BackfillChapterQuizzesCommandTest extends LearningTestCase
{
    public function test_it_reports_nothing_to_do_when_every_chapter_has_a_quiz(): void
    {
        Chapter::factory()->count(2)->withQuiz()->create();

        $this->artisan('learning:backfill-chapter-quizzes')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame(2, Quiz::query()->count());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        Chapter::factory()->create(['title' => 'Legacy chapter']);

        $this->artisan('learning:backfill-chapter-quizzes --dry-run')
            ->expectsOutputToContain('1 chapter(s) have no quiz')
            ->expectsOutputToContain('Legacy chapter Quiz')
            ->assertSuccessful();

        $this->assertSame(0, Quiz::query()->count());
    }

    public function test_it_creates_the_missing_quiz_with_the_default_title_and_difficulty(): void
    {
        $chapter = Chapter::factory()->create(['title' => 'Legacy chapter']);

        $this->artisan('learning:backfill-chapter-quizzes')->assertSuccessful();

        $quiz = $chapter->refresh()->quiz;

        $this->assertNotNull($quiz);
        $this->assertSame('Legacy chapter Quiz', $quiz->title);
        $this->assertSame(QuizDifficulty::Medium, $quiz->difficulty);
    }

    public function test_it_is_idempotent(): void
    {
        Chapter::factory()->create();

        $this->artisan('learning:backfill-chapter-quizzes')->assertSuccessful();
        $this->artisan('learning:backfill-chapter-quizzes')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame(1, Quiz::query()->count());
    }

    public function test_it_also_repairs_soft_deleted_chapters_without_touching_their_state(): void
    {
        $chapter = Chapter::factory()->create();
        $chapter->delete();

        $this->artisan('learning:backfill-chapter-quizzes')->assertSuccessful();

        $this->assertSoftDeleted('chapters', ['id' => $chapter->id]);
        $this->assertSame(1, Quiz::query()->count());
    }

    public function test_it_never_creates_a_second_quiz_for_a_chapter_whose_quiz_is_trashed(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();
        $chapter->quiz->delete();

        $this->artisan('learning:backfill-chapter-quizzes')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertNull(app(ChapterService::class)->backfillMissingQuiz($chapter->refresh()));
        $this->assertSame(1, Quiz::withTrashed()->count());
    }
}
