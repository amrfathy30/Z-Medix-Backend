<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\ChapterResource\RelationManagers\PagesRelationManager;
use App\Models\Chapter;
use App\Models\ChapterPage;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Livewire;

class ChapterPageTest extends LearningTestCase
{
    private function pagesTable(Chapter $chapter)
    {
        return Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(PagesRelationManager::class, [
                'ownerRecord' => $chapter,
                'pageClass' => ViewChapter::class,
            ]);
    }

    public function test_page_belongs_to_a_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $page = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);

        $this->assertTrue($page->chapter->is($chapter));
        $this->assertTrue($chapter->pages->contains($page));
    }

    // ─── A page has no title ─────────────────────────────────────────────────

    public function test_chapter_pages_no_longer_store_a_title(): void
    {
        $this->assertFalse(DatabaseSchema::hasColumn('chapter_pages', 'title'));
        $this->assertNotContains('title', (new ChapterPage)->getFillable());
    }

    public function test_the_page_form_never_asks_for_a_title(): void
    {
        $relationManager = new PagesRelationManager;

        $componentNames = collect($relationManager->form(Schema::make($relationManager))->getFlatComponents(withHidden: true))
            ->map(fn (object $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['content'], $componentNames);
    }

    public function test_page_can_be_created_from_the_chapter_with_content_only(): void
    {
        $chapter = Chapter::factory()->create();

        $this->pagesTable($chapter)
            ->callAction(TestAction::make('create')->table(), [
                'content' => '<p>Study content</p>',
            ])
            ->assertHasNoActionErrors();

        $page = $chapter->pages()->sole();

        $this->assertSame($chapter->id, $page->chapter_id);
        $this->assertSame('<p>Study content</p>', $page->content);
        $this->assertSame(1, $page->order);
    }

    public function test_page_content_is_optional_so_a_page_can_be_drafted_first(): void
    {
        $chapter = Chapter::factory()->create();

        $this->pagesTable($chapter)
            ->callAction(TestAction::make('create')->table(), ['content' => null])
            ->assertHasNoActionErrors();

        $page = $chapter->pages()->sole();

        $this->assertSame('', strip_tags((string) $page->content));
    }

    // ─── Pages are identified by their position ──────────────────────────────

    public function test_pages_are_labelled_by_their_position_in_the_chapter(): void
    {
        $chapter = Chapter::factory()->create();

        $first = ChapterPage::factory()->order(1)->create(['chapter_id' => $chapter->id]);
        $second = ChapterPage::factory()->order(2)->create(['chapter_id' => $chapter->id]);
        $third = ChapterPage::factory()->order(3)->create(['chapter_id' => $chapter->id]);

        $this->assertSame(1, $first->positionInChapter());
        $this->assertSame(2, $second->positionInChapter());
        $this->assertSame(3, $third->positionInChapter());

        $this->assertSame('Page 1', $first->pageLabel());
        $this->assertSame('Page 2', $second->pageLabel());
        $this->assertSame('Page 3', $third->pageLabel());
    }

    public function test_page_numbering_is_scoped_to_the_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $otherChapter = Chapter::factory()->create();

        $ownFirst = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);
        $otherFirst = ChapterPage::factory()->create(['chapter_id' => $otherChapter->id]);

        $this->assertSame('Page 1', $ownFirst->pageLabel());
        $this->assertSame('Page 1', $otherFirst->pageLabel());
    }

    public function test_the_pages_table_shows_the_sequence_numbers(): void
    {
        $chapter = Chapter::factory()->create();

        $first = ChapterPage::factory()->order(1)->create(['chapter_id' => $chapter->id]);
        $second = ChapterPage::factory()->order(2)->create(['chapter_id' => $chapter->id]);

        $this->pagesTable($chapter)
            ->assertCanSeeTableRecords([$first, $second])
            ->assertTableColumnStateSet('page_number', 'Page 1', $first)
            ->assertTableColumnStateSet('page_number', 'Page 2', $second)
            ->assertSee('Page 1')
            ->assertSee('Page 2');
    }

    public function test_chapter_only_shows_its_own_pages(): void
    {
        $chapter = Chapter::factory()->create();
        $otherChapter = Chapter::factory()->create();

        $own = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);
        $foreign = ChapterPage::factory()->create(['chapter_id' => $otherChapter->id]);

        $this->pagesTable($chapter)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    // ─── Ordering ───────────────────────────────────────────────────────────

    public function test_page_order_is_assigned_sequentially_within_its_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $otherChapter = Chapter::factory()->create();

        $first = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);
        $second = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);
        $otherFirst = ChapterPage::factory()->create(['chapter_id' => $otherChapter->id]);

        $this->assertSame(1, $first->order);
        $this->assertSame(2, $second->order);
        $this->assertSame(1, $otherFirst->order);
    }

    public function test_pages_are_returned_in_order(): void
    {
        $chapter = Chapter::factory()->create();

        $third = ChapterPage::factory()->order(3)->content('<p>Third</p>')->create(['chapter_id' => $chapter->id]);
        $first = ChapterPage::factory()->order(1)->content('<p>First</p>')->create(['chapter_id' => $chapter->id]);
        $second = ChapterPage::factory()->order(2)->content('<p>Second</p>')->create(['chapter_id' => $chapter->id]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $chapter->pages()->pluck('id')->all(),
        );
    }

    public function test_the_pages_table_is_reorderable_by_drag_and_drop(): void
    {
        $chapter = Chapter::factory()->create();

        $this->assertSame('order', $this->pagesTable($chapter)->instance()->getTable()->getReorderColumn());
    }

    public function test_dragging_the_last_page_to_the_front_renumbers_every_page(): void
    {
        $chapter = Chapter::factory()->create();

        $one = ChapterPage::factory()->order(1)->create(['chapter_id' => $chapter->id]);
        $two = ChapterPage::factory()->order(2)->create(['chapter_id' => $chapter->id]);
        $three = ChapterPage::factory()->order(3)->create(['chapter_id' => $chapter->id]);

        $this->pagesTable($chapter)
            ->call('reorderTable', [$three->getKey(), $one->getKey(), $two->getKey()]);

        $this->assertSame(1, $three->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);
        $this->assertSame(3, $two->refresh()->order);

        // Previously "Page 3" is now "Page 1", and the others shift down by one.
        $this->assertSame('Page 1', $three->pageLabel());
        $this->assertSame('Page 2', $one->pageLabel());
        $this->assertSame('Page 3', $two->pageLabel());
    }

    public function test_reordering_one_chapters_pages_never_touches_another_chapter(): void
    {
        $chapter = Chapter::factory()->create();
        $otherChapter = Chapter::factory()->create();

        $one = ChapterPage::factory()->order(1)->create(['chapter_id' => $chapter->id]);
        $two = ChapterPage::factory()->order(2)->create(['chapter_id' => $chapter->id]);

        $otherOne = ChapterPage::factory()->order(1)->create(['chapter_id' => $otherChapter->id]);
        $otherTwo = ChapterPage::factory()->order(2)->create(['chapter_id' => $otherChapter->id]);

        $this->pagesTable($chapter)
            ->call('reorderTable', [$two->getKey(), $one->getKey()]);

        $this->assertSame(1, $two->refresh()->order);
        $this->assertSame(2, $one->refresh()->order);

        $this->assertSame(1, $otherOne->refresh()->order);
        $this->assertSame(2, $otherTwo->refresh()->order);
    }

    // ─── Content ────────────────────────────────────────────────────────────

    public function test_page_content_can_be_saved_and_updated(): void
    {
        $chapter = Chapter::factory()->create();
        $page = ChapterPage::factory()->content('<p>Original</p>')->create(['chapter_id' => $chapter->id]);

        $this->pagesTable($chapter)
            ->callAction(TestAction::make('edit')->table($page), [
                'content' => '<p>Updated content with <strong>markup</strong></p>',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('<p>Updated content with <strong>markup</strong></p>', $page->refresh()->content);
    }

    public function test_page_can_be_soft_deleted_and_restored(): void
    {
        $chapter = Chapter::factory()->create();
        $page = ChapterPage::factory()->create(['chapter_id' => $chapter->id]);

        $this->pagesTable($chapter)
            ->callAction(TestAction::make('delete')->table($page));

        $this->assertSoftDeleted('chapter_pages', ['id' => $page->id]);

        $this->pagesTable($chapter)
            ->filterTable('trashed', false)
            ->callAction(TestAction::make('restore')->table($page));

        $this->assertDatabaseHas('chapter_pages', ['id' => $page->id, 'deleted_at' => null]);
    }
}
