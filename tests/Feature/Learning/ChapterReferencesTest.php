<?php

namespace Tests\Feature\Learning;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource\Pages\EditChapter;
use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Chapter;
use App\Models\Subject;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * The chapter's `references` field: where its educational content was taken
 * from, as one optional line the admin types.
 *
 * It is optional everywhere, which is what keeps every chapter written before
 * the field valid, and it is capped at the 255 characters the column holds.
 */
class ChapterReferencesTest extends LearningTestCase
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
    private function createChapter(Subject $subject, array $data = [])
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

    public function test_a_chapter_is_created_with_its_references(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject, ['references' => 'Gray\'s Anatomy, 42nd ed., ch. 7'])
            ->assertHasNoActionErrors();

        $this->assertSame(
            'Gray\'s Anatomy, 42nd ed., ch. 7',
            Chapter::query()->where('title', 'Upper Limb')->sole()->references,
        );
    }

    public function test_references_are_optional_on_create(): void
    {
        $subject = Subject::factory()->create();

        $this->createChapter($subject)->assertHasNoActionErrors();

        $this->assertNull(Chapter::query()->where('title', 'Upper Limb')->sole()->references);
    }

    public function test_the_edit_form_offers_references_and_saves_them(): void
    {
        $chapter = Chapter::factory()->draft()->withQuiz()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('references')
            ->fillForm(['references' => 'Harrison\'s Principles of Internal Medicine'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'Harrison\'s Principles of Internal Medicine',
            $chapter->refresh()->references,
        );
    }

    public function test_references_may_be_cleared(): void
    {
        $chapter = Chapter::factory()->draft()->withQuiz()->create(['references' => 'Some source']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm(['references' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($chapter->refresh()->references);
    }

    public function test_references_longer_than_the_column_are_refused(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm(['references' => str_repeat('a', 256)])
            ->call('save')
            ->assertHasFormErrors(['references' => 'max']);

        $this->assertNull($chapter->refresh()->references);
    }

    public function test_exactly_the_maximum_length_is_accepted(): void
    {
        $chapter = Chapter::factory()->draft()->withQuiz()->create();
        $reference = str_repeat('a', 255);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditChapter::class, ['record' => $chapter->getRouteKey()])
            ->fillForm(['references' => $reference])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($reference, $chapter->refresh()->references);
    }

    public function test_existing_chapters_keep_null_references(): void
    {
        $chapter = Chapter::factory()->create();

        $this->assertNull($chapter->references);
    }

    public function test_the_view_page_shows_the_references(): void
    {
        $chapter = Chapter::factory()->withQuiz()->create(['references' => 'Cited source']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewChapter::class, ['record' => $chapter->getRouteKey()])
            ->assertOk()
            ->assertSee('Cited source');
    }
}
