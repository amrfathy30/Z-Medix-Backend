<?php

namespace Tests\Feature\Learning;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource\Pages\EditChapter;
use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Subject;
use App\Services\Learning\ChapterService;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Livewire\Livewire;

class ChapterTest extends LearningTestCase
{
    private function chaptersTable(Subject $subject)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ChaptersRelationManager::class, [
                'ownerRecord' => $subject,
                'pageClass' => ViewSubject::class,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createChapter(Subject $subject, array $data)
    {
        return $this->chaptersTable($subject)
            ->callAction(TestAction::make('create')->table(), array_replace([
                'title' => 'Upper Limb',
                'quiz' => [
                    'title' => 'Upper Limb Quiz',
                    'difficulty' => QuizDifficulty::Medium->value,
                ],
            ], $data));
    }

    public function test_chapter_belongs_to_a_subject(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);

        $this->assertTrue($chapter->subject->is($subject));
        $this->assertTrue($subject->chapters->contains($chapter));
    }

    public function test_chapter_can_be_created_from_the_subject_with_subject_id_assigned_automatically(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['title' => 'Upper Limb'])
            ->assertHasNoActionErrors();

        $chapter = Chapter::query()->where('title', 'Upper Limb')->sole();

        $this->assertSame($subject->id, $chapter->subject_id);
        $this->assertSame(ContentStatus::Draft, $chapter->status);
    }

    /** @return array<int, string> */
    private function createFormFieldNames(): array
    {
        $relationManager = new ChaptersRelationManager;

        return collect($relationManager->form(Schema::make($relationManager))->getFlatComponents(withHidden: true))
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();
    }

    public function test_chapter_create_form_asks_for_the_title_and_quiz_only(): void
    {
        $subject = Subject::factory()->create();

        $this->chaptersTable($subject)
            ->mountAction(TestAction::make('create')->table())
            ->assertActionMounted(TestAction::make('create')->table());

        $componentNames = $this->createFormFieldNames();

        $this->assertContains('title', $componentNames);
        $this->assertContains('quiz.title', $componentNames);
        $this->assertContains('quiz.difficulty', $componentNames);
        $this->assertNotContains('subject_id', $componentNames);
    }

    public function test_the_create_form_offers_no_status_choice_so_published_cannot_be_picked(): void
    {
        $this->assertNotContains('status', $this->createFormFieldNames());
    }

    public function test_the_create_form_offers_no_manual_order_input(): void
    {
        $this->assertNotContains('order', $this->createFormFieldNames());
    }

    public function test_the_edit_form_offers_no_manual_order_input(): void
    {
        $chapter = Chapter::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('status')
            ->assertFormFieldDoesNotExist('order');
    }

    public function test_chapter_title_is_required(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['title' => null])
            ->assertHasActionErrors(['title' => 'required']);
    }

    public function test_subject_only_shows_its_own_chapters(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $own = Chapter::factory()->create(['subject_id' => $subject->id]);
        $foreign = Chapter::factory()->create(['subject_id' => $otherSubject->id]);

        $this->chaptersTable($subject)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_chapter_order_is_assigned_sequentially_within_its_subject(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $first = Chapter::factory()->create(['subject_id' => $subject->id]);
        $second = Chapter::factory()->create(['subject_id' => $subject->id]);
        $otherFirst = Chapter::factory()->create(['subject_id' => $otherSubject->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);

        // The same numeric order may exist under a different subject.
        $this->assertSame(1, $otherFirst->order);
    }

    public function test_chapters_are_returned_in_a_deterministic_order(): void
    {
        $subject = Subject::factory()->create();

        $third = Chapter::factory()->order(3)->create(['subject_id' => $subject->id, 'title' => 'Third']);
        $first = Chapter::factory()->order(1)->create(['subject_id' => $subject->id, 'title' => 'First']);
        $second = Chapter::factory()->order(2)->create(['subject_id' => $subject->id, 'title' => 'Second']);

        $this->assertSame(
            ['First', 'Second', 'Third'],
            $subject->chapters()->pluck('title')->all(),
        );
        $this->assertSame([$first->id, $second->id, $third->id], $subject->chapters()->pluck('id')->all());
    }

    public function test_a_new_chapter_is_appended_to_the_end_of_its_subject(): void
    {
        $subject = Subject::factory()->create();
        Chapter::factory()->order(1)->create(['subject_id' => $subject->id]);
        Chapter::factory()->order(2)->create(['subject_id' => $subject->id]);

        $this->createChapter($subject, ['title' => 'Thorax'])->assertHasNoActionErrors();

        $this->assertDatabaseHas('chapters', [
            'title' => 'Thorax',
            'subject_id' => $subject->id,
            'order' => 3,
        ]);
    }

    public function test_the_chapters_table_is_reorderable_by_drag_and_drop(): void
    {
        $subject = Subject::factory()->create();

        $this->assertSame('order', $this->chaptersTable($subject)->instance()->getTable()->getReorderColumn());
    }

    public function test_dragging_a_chapter_row_persists_the_new_order(): void
    {
        $subject = Subject::factory()->create();
        $one = Chapter::factory()->order(1)->create(['subject_id' => $subject->id]);
        $two = Chapter::factory()->order(2)->create(['subject_id' => $subject->id]);
        $three = Chapter::factory()->order(3)->create(['subject_id' => $subject->id]);

        $this->chaptersTable($subject)
            ->call('reorderTable', [$three->getKey(), $one->getKey(), $two->getKey()]);

        $this->assertSame(1, $three->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);
        $this->assertSame(3, $two->refresh()->order);

        $this->assertSame(
            [$three->id, $one->id, $two->id],
            $subject->chapters()->pluck('id')->all(),
        );
    }

    public function test_reordering_one_subjects_chapters_never_touches_another_subject(): void
    {
        $subject = Subject::factory()->create();
        $otherSubject = Subject::factory()->create();

        $one = Chapter::factory()->order(1)->create(['subject_id' => $subject->id]);
        $two = Chapter::factory()->order(2)->create(['subject_id' => $subject->id]);

        $otherOne = Chapter::factory()->order(1)->create(['subject_id' => $otherSubject->id]);
        $otherTwo = Chapter::factory()->order(2)->create(['subject_id' => $otherSubject->id]);

        $this->chaptersTable($subject)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $two->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);

        $this->assertSame(1, $otherOne->refresh()->order);
        $this->assertSame(2, $otherTwo->refresh()->order);
    }

    public function test_chapter_status_is_stored_as_the_content_status_enum(): void
    {
        $chapter = Chapter::factory()->draft()->create();

        $this->assertSame(ContentStatus::Draft, $chapter->refresh()->status);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'status' => 'draft']);
    }

    public function test_a_new_chapter_is_always_created_as_a_draft(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['title' => 'Brand new'])->assertHasNoActionErrors();

        $chapter = Chapter::query()->where('title', 'Brand new')->sole();

        $this->assertSame(ContentStatus::Draft, $chapter->status);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'status' => 'draft']);
    }

    public function test_the_service_forces_draft_even_when_a_published_status_is_supplied(): void
    {
        $subject = Subject::factory()->create();

        // Simulates a crafted request that bypasses the form entirely.
        $chapter = app(ChapterService::class)->createWithQuiz(
            $subject,
            ['title' => 'Smuggled publish', 'status' => ContentStatus::Published->value],
            ['title' => 'Smuggled publish Quiz', 'difficulty' => QuizDifficulty::Easy->value],
        );

        $this->assertSame(ContentStatus::Draft, $chapter->refresh()->status);
        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'status' => 'draft']);
    }

    public function test_the_service_forces_draft_even_when_an_archived_status_is_supplied(): void
    {
        $subject = Subject::factory()->create();

        $chapter = app(ChapterService::class)->createWithQuiz(
            $subject,
            ['title' => 'Smuggled archive', 'status' => ContentStatus::Archived->value],
            ['title' => 'Smuggled archive Quiz', 'difficulty' => QuizDifficulty::Easy->value],
        );

        $this->assertSame(ContentStatus::Draft, $chapter->refresh()->status);
    }

    public function test_a_draft_chapter_can_exist_and_be_edited_while_its_quiz_has_no_questions(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['title' => 'Still empty'])->assertHasNoActionErrors();

        $chapter = Chapter::query()->where('title', 'Still empty')->sole();

        $this->assertSame(0, $chapter->quizQuestionCount());
        $this->assertFalse($chapter->isPublishable());

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm([
                'title' => 'Still empty, renamed',
                'status' => ContentStatus::Draft->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Still empty, renamed', $chapter->refresh()->title);
        $this->assertSame(ContentStatus::Draft, $chapter->status);
    }

    public function test_a_chapter_cannot_be_published_while_its_quiz_has_no_questions(): void
    {
        $chapter = Chapter::factory()->draft()->withQuiz()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $chapter->refresh()->status);
        $this->assertFalse($chapter->isPublishable());
    }

    public function test_a_chapter_can_be_published_once_its_quiz_has_a_question(): void
    {
        $chapter = Chapter::factory()->draft()->withQuiz()->create();
        Question::factory()->withOptions(2)->create(['quiz_id' => $chapter->quiz->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $chapter->refresh()->status);
        $this->assertTrue($chapter->isPublishable());
    }

    public function test_chapter_view_loads_with_its_overview_and_quiz_section(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewChapter::class, ['record' => $chapter->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('chapter-overview')
            ->assertSchemaComponentExists('quiz-section')
            ->assertSee($chapter->title)
            ->assertSee($chapter->subject->name);
    }

    public function test_chapter_view_counts_its_pages(): void
    {
        $chapter = Chapter::factory()->create();
        ChapterPage::factory()->count(2)->create(['chapter_id' => $chapter->id]);
        ChapterPage::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewChapter::class, ['record' => $chapter->getRouteKey()])
            ->assertOk();

        $this->assertSame(2, $chapter->pages()->count());
    }

    public function test_chapter_can_be_edited(): void
    {
        $chapter = Chapter::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm([
                'title' => 'Renamed Chapter',
                'status' => ContentStatus::Draft->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('chapters', [
            'id' => $chapter->id,
            'title' => 'Renamed Chapter',
            'order' => $chapter->order,
        ]);
    }

    public function test_chapter_table_shows_page_count_and_quiz_title(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);
        ChapterPage::factory()->count(2)->create(['chapter_id' => $chapter->id]);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id, 'title' => 'Chapter Quiz']);

        $this->chaptersTable($subject)
            ->assertCanSeeTableRecords([$chapter])
            ->assertTableColumnStateSet('pages_count', 2, $chapter)
            ->assertTableColumnStateSet('quiz.title', $quiz->title, $chapter);
    }

    public function test_chapter_can_be_soft_deleted_and_restored_from_the_subject(): void
    {
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);

        $this->chaptersTable($subject)
            ->callAction(TestAction::make('delete')->table($chapter));

        $this->assertSoftDeleted('chapters', ['id' => $chapter->id]);

        $this->chaptersTable($subject)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($chapter));

        $this->assertDatabaseHas('chapters', ['id' => $chapter->id, 'deleted_at' => null]);
    }
}
