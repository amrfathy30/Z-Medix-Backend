<?php

namespace Tests\Feature\Learning;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\ChapterResource\RelationManagers\PagesRelationManager;
use App\Filament\Resources\QuizResource\Pages\ViewQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\Subject;
use App\Services\Learning\ChapterService;
use Livewire\Livewire;

class RelationalIntegrityTest extends LearningTestCase
{
    public function test_a_subject_never_displays_a_chapter_belonging_to_another_subject(): void
    {
        $subject = Subject::factory()->create();
        $foreign = Chapter::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ChaptersRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertSame(0, $subject->chapters()->count());
    }

    public function test_a_chapter_never_displays_a_page_belonging_to_another_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $foreign = ChapterPage::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(PagesRelationManager::class, [
                'ownerRecord' => $chapter,
                'pageClass' => ViewChapter::class,
            ])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertSame(0, $chapter->pages()->count());
    }

    public function test_a_chapter_displays_only_its_own_quiz(): void
    {
        $chapter = Chapter::factory()->create();
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id]);
        $foreignQuiz = Quiz::factory()->create();

        $this->assertTrue($chapter->refresh()->quiz->is($quiz));
        $this->assertFalse($chapter->quiz->is($foreignQuiz));
    }

    public function test_a_quiz_never_displays_a_question_belonging_to_another_quiz(): void
    {
        $quiz = Quiz::factory()->create();
        $foreign = Question::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(QuestionsRelationManager::class, [
                'ownerRecord' => $quiz,
                'pageClass' => ViewQuiz::class,
            ])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertSame(0, $quiz->questions()->count());
    }

    public function test_a_question_displays_only_its_own_options(): void
    {
        $question = Question::factory()->create();
        $own = QuestionOption::factory()->create(['question_id' => $question->id]);
        $foreign = QuestionOption::factory()->create();

        $options = $question->options()->pluck('id')->all();

        $this->assertContains($own->id, $options);
        $this->assertNotContains($foreign->id, $options);
    }

    public function test_deleting_a_subject_outright_removes_its_whole_content_tree(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);
        $page = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id]);
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);

        $subject->forceDelete();

        $this->assertDatabaseMissing('chapters', ['id' => $chapter->id]);
        $this->assertDatabaseMissing('chapter_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
        $this->assertDatabaseMissing('question_options', ['id' => $option->id]);
    }

    public function test_soft_deleting_a_subject_keeps_its_tree_restorable(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);

        $subject->delete();

        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'deleted_at' => null]);

        $subject->restore();

        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'deleted_at' => null]);
        $this->assertTrue($subject->refresh()->chapters->contains($chapter));
    }

    public function test_every_chapter_created_through_the_domain_path_owns_exactly_one_quiz(): void
    {
        $subject = Subject::factory()->create();

        app(ChapterService::class)->createWithQuiz(
            $subject,
            ['title' => 'Invariant chapter', 'status' => ContentStatus::Draft->value],
            ['title' => 'Invariant chapter Quiz', 'difficulty' => QuizDifficulty::Easy->value],
        );

        $chapter = Chapter::query()->where('title', 'Invariant chapter')->sole();

        $this->assertSame(1, Quiz::query()->where('chapter_id', $chapter->id)->count());
        $this->assertSame($chapter->id, $chapter->quiz->chapter_id);
    }

    public function test_soft_deleting_a_subject_leaves_its_chapters_and_their_quizzes_untouched(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->withQuiz()->create(['subject_id' => $subject->id]);
        $quiz = $chapter->quiz;

        $subject->delete();

        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_quiz_outright_removes_its_questions_and_options(): void
    {
        $quiz = Quiz::factory()->create();
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);
        $option = QuestionOption::factory()->create(['question_id' => $question->id]);

        $quiz->forceDelete();

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
        $this->assertDatabaseMissing('question_options', ['id' => $option->id]);
    }
}
