<?php

namespace Tests\Feature\Learning;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\Pages\ViewQuiz;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Chapter;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Subject;
use App\Services\Learning\ChapterService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class QuizTest extends LearningTestCase
{
    private function chapterView(Chapter $chapter)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewChapter::class, ['record' => $chapter->getRouteKey()]);
    }

    /**
     * The quiz-related actions live inside the `quiz-actions` schema component on
     * the chapter's view page, not in the page header.
     */
    private function quizAction(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('quiz-section.quiz-actions');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createChapter(Subject $subject, array $data = [])
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ChaptersRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ])
            ->callAction(TestAction::make('create')->table(), array_replace([
                'title' => 'Upper Limb',
                'status' => ContentStatus::Draft->value,
                'quiz' => [
                    'title' => 'Upper Limb Quiz',
                    'difficulty' => QuizDifficulty::Hard->value,
                ],
            ], $data));
    }

    // ─── Creation is atomic with the chapter ──────────────────────────────────

    public function test_creating_a_chapter_also_creates_its_quiz(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject)->assertHasNoActionErrors();

        $chapter = Chapter::query()->where('title', 'Upper Limb')->sole();

        $this->assertNotNull($chapter->quiz);
        $this->assertSame($chapter->id, $chapter->quiz->chapter_id);
        $this->assertSame('Upper Limb Quiz', $chapter->quiz->title);
        $this->assertSame(QuizDifficulty::Hard, $chapter->quiz->difficulty);
    }

    public function test_quiz_title_and_difficulty_are_required_when_creating_a_chapter(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['quiz' => ['title' => null, 'difficulty' => null]])
            ->assertHasActionErrors([
                'quiz.title' => 'required',
                'quiz.difficulty' => 'required',
            ]);

        $this->assertDatabaseCount('chapters', 0);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_only_easy_medium_and_hard_difficulties_are_accepted(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['quiz' => ['title' => 'Bad', 'difficulty' => 'impossible']])
            ->assertHasActionErrors(['quiz.difficulty']);

        $this->assertDatabaseCount('quizzes', 0);

        $this->assertSame(
            ['easy', 'medium', 'hard'],
            array_map(fn (QuizDifficulty $case): string => $case->value, QuizDifficulty::cases()),
        );
    }

    public function test_chapter_and_quiz_creation_is_atomic(): void
    {
        $subject = Subject::factory()->create();

        // Force the quiz insert to fail after the chapter row is written.
        Quiz::creating(fn (): never => throw new \RuntimeException('quiz insert failed'));

        try {
            app(ChapterService::class)->createWithQuiz(
                $subject,
                ['title' => 'Rolled back', 'status' => ContentStatus::Draft->value],
                ['title' => 'Rolled back Quiz', 'difficulty' => QuizDifficulty::Easy->value],
            );
            $this->fail('Expected the quiz failure to abort the chapter creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('quiz insert failed', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('chapters')->count());
        $this->assertSame(0, DB::table('quizzes')->count());
    }

    public function test_quiz_title_defaults_to_the_chapter_title_when_left_blank(): void
    {
        $subject = Subject::factory()->create();

        app(ChapterService::class)->createWithQuiz(
            $subject,
            ['title' => 'Lower Limb', 'status' => ContentStatus::Draft->value],
            ['title' => null, 'difficulty' => QuizDifficulty::Easy->value],
        );

        $this->assertDatabaseHas('quizzes', ['title' => 'Lower Limb Quiz']);
    }

    // ─── Relationship and schema stay as approved ────────────────────────────

    public function test_quiz_belongs_to_a_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id]);

        $this->assertTrue($quiz->chapter->is($chapter));
        $this->assertTrue($chapter->refresh()->quiz->is($quiz));
    }

    public function test_chapter_id_is_unique_so_a_second_quiz_is_rejected(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();

        $this->expectException(QueryException::class);

        Quiz::factory()->create(['chapter_id' => $chapter->id]);
    }

    public function test_quiz_resolves_its_subject_through_its_chapter(): void
    {
        $subject = Subject::factory()->create(['name' => 'Histology']);
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id]);

        $this->assertTrue($quiz->subject()->is($subject));

        $this->assertFalse(Schema::hasColumn('quizzes', 'subject_id'));
        $this->assertFalse(Schema::hasColumn('quizzes', 'order'));
    }

    public function test_there_is_no_quiz_question_pivot_table(): void
    {
        $this->assertFalse(Schema::hasTable('quiz_question'));
        $this->assertTrue(Schema::hasColumn('questions', 'quiz_id'));
    }

    // ─── Quiz keeps soft deletes but has no independent lifecycle ────────────

    public function test_quiz_still_uses_soft_deletes(): void
    {
        $this->assertTrue(Schema::hasColumn('quizzes', 'deleted_at'));
        $this->assertContains(
            SoftDeletes::class,
            class_uses_recursive(Quiz::class),
        );
    }

    public function test_admins_can_never_delete_restore_or_force_delete_a_quiz(): void
    {
        $quiz = Quiz::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertFalse(QuizResource::canDelete($quiz));
        $this->assertFalse(QuizResource::canRestore($quiz));
        $this->assertFalse(QuizResource::canForceDelete($quiz));
        $this->assertTrue(QuizResource::canEdit($quiz));
        $this->assertTrue(QuizResource::canView($quiz));
    }

    public function test_the_quiz_edit_page_exposes_no_delete_restore_or_force_delete_action(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuiz::class, ['record' => $quiz->getRouteKey()])
            ->assertOk()
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('restore')
            ->assertActionDoesNotExist('forceDelete');
    }

    public function test_the_chapter_view_has_no_create_delete_or_restore_quiz_action(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();

        $this->chapterView($chapter)
            ->assertActionDoesNotExist($this->quizAction('createQuiz'))
            ->assertActionDoesNotExist($this->quizAction('restoreQuiz'))
            ->assertActionDoesNotExist($this->quizAction('deleteQuiz'))
            ->assertActionVisible($this->quizAction('viewQuiz'))
            ->assertActionVisible($this->quizAction('editQuiz'));
    }

    // ─── Quiz follows the chapter's lifecycle ────────────────────────────────

    public function test_soft_deleting_a_chapter_soft_deletes_its_quiz(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();
        $quiz = $chapter->quiz;

        $chapter->delete();

        $this->assertSoftDeleted('chapters', ['id' => $chapter->id]);
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
    }

    public function test_restoring_a_chapter_restores_its_quiz(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();
        $quiz = $chapter->quiz;

        $chapter->delete();
        $chapter->restore();

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'deleted_at' => null]);
        $this->assertTrue($chapter->refresh()->quiz->is($quiz));
    }

    public function test_restoring_a_chapter_from_the_subject_table_restores_its_quiz(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->withQuiz()->create(['subject_id' => $subject->id]);
        $quiz = $chapter->quiz;

        $table = fn () => Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ChaptersRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);

        $table()->callAction(TestAction::make('delete')->table($chapter));
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);

        $table()->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($chapter));

        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'deleted_at' => null]);
    }

    public function test_force_deleting_a_chapter_removes_its_quiz(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();
        $quiz = $chapter->quiz;

        $chapter->forceDelete();

        $this->assertDatabaseMissing('quizzes', ['id' => $quiz->id]);
    }

    // ─── Chapter view surfaces the quiz state ────────────────────────────────

    public function test_chapter_view_always_shows_the_quiz_for_a_valid_record(): void
    {
        $chapter = Chapter::factory()->withQuiz('Chapter One Quiz', QuizDifficulty::Easy)->create();

        $this->chapterView($chapter->refresh())
            ->assertSchemaComponentExists('quiz-section.quiz-summary')
            ->assertSchemaComponentHidden('quiz-section.quiz-missing-state')
            ->assertSee('Chapter One Quiz')
            ->assertSee(__('admin.learning.difficulty_easy'));
    }

    public function test_chapter_view_warns_when_the_quiz_has_no_questions(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();

        $this->chapterView($chapter->refresh())
            ->assertSchemaComponentVisible('quiz-section.quiz-no-questions-warning')
            ->assertSee(__('admin.learning.quiz_no_questions_warning'));
    }

    public function test_chapter_view_drops_the_warning_once_the_quiz_has_a_question(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();
        Question::factory()->withOptions(2)->create(['quiz_id' => $chapter->quiz->id]);

        $this->chapterView($chapter->refresh())
            ->assertSchemaComponentHidden('quiz-section.quiz-no-questions-warning');
    }

    public function test_chapter_view_shows_a_repair_warning_for_a_legacy_chapter_without_a_quiz(): void
    {
        $chapter = Chapter::factory()->create();

        $this->chapterView($chapter)
            ->assertOk()
            ->assertSchemaComponentVisible('quiz-section.quiz-missing-state')
            ->assertSee(__('admin.learning.quiz_missing_heading'));
    }

    // ─── Quiz pages ─────────────────────────────────────────────────────────

    public function test_quiz_view_loads_with_the_subject_derived_through_the_chapter(): void
    {
        $subject = Subject::factory()->create(['name' => 'Pharmacology']);
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id, 'title' => 'Antibiotics']);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Antibiotics Quiz']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewQuiz::class, ['record' => $quiz->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('quiz-overview')
            ->assertSee('Antibiotics Quiz')
            ->assertSee('Antibiotics')
            ->assertSee('Pharmacology');
    }

    public function test_quiz_view_warns_while_it_has_no_questions(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewQuiz::class, ['record' => $quiz->getRouteKey()])
            ->assertSchemaComponentVisible('quiz-no-questions-warning')
            ->assertSee(__('admin.learning.quiz_no_questions_warning'));

        Question::factory()->withOptions(2)->create(['quiz_id' => $quiz->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewQuiz::class, ['record' => $quiz->getRouteKey()])
            ->assertSchemaComponentHidden('quiz-no-questions-warning');
    }

    public function test_quiz_can_be_edited(): void
    {
        $quiz = Quiz::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditQuiz::class, ['record' => $quiz->getRouteKey()])
            ->fillForm([
                'title' => 'Renamed Quiz',
                'difficulty' => QuizDifficulty::Hard->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'title' => 'Renamed Quiz',
            'difficulty' => 'hard',
        ]);
    }
}
