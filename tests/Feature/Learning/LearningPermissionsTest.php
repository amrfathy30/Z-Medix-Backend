<?php

namespace Tests\Feature\Learning;

use App\Filament\Resources\BookResource;
use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuestionResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Filament\Resources\SubjectResource\Pages\ListSubjects;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Subject;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

class LearningPermissionsTest extends LearningTestCase
{
    public function test_learning_content_permissions_are_seeded(): void
    {
        $expected = [];

        foreach (['subjects', 'chapters', 'chapter_pages', 'books', 'book_pages', 'quizzes', 'questions'] as $family) {
            foreach (['view', 'create', 'update', 'delete'] as $ability) {
                $expected[] = "{$family}.{$ability}";
            }
        }

        $seeded = Permission::query()->where('guard_name', 'admin')->pluck('name')->all();

        foreach ($expected as $permission) {
            $this->assertContains($permission, $seeded);
        }
    }

    public function test_seeding_permissions_again_is_idempotent(): void
    {
        $before = Permission::query()->where('guard_name', 'admin')->count();

        $this->seed(RolesPermissionsSeeder::class);

        $this->assertSame($before, Permission::query()->where('guard_name', 'admin')->count());
    }

    public function test_content_manager_can_manage_the_whole_learning_tree(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $subject = Subject::factory()->create();
        $chapter = Chapter::factory()->create(['subject_id' => $subject->id]);
        $quiz = Quiz::factory()->create(['chapter_id' => $chapter->id]);
        $question = Question::factory()->create(['quiz_id' => $quiz->id]);

        $this->actingAs($admin, 'admin');

        $this->assertTrue(SubjectResource::canViewAny());
        $this->assertTrue(SubjectResource::canCreate());
        $this->assertTrue(SubjectResource::canEdit($subject));
        $this->assertTrue(SubjectResource::canDelete($subject));
        $this->assertTrue(ChapterResource::canEdit($chapter));
        $this->assertTrue(QuizResource::canEdit($quiz));
        $this->assertTrue(QuestionResource::canEdit($question));

        $book = Book::factory()->create(['subject_id' => $subject->id]);

        $this->assertTrue(BookResource::canViewAny());
        $this->assertTrue(BookResource::canCreate());
        $this->assertTrue(BookResource::canView($book));
        $this->assertTrue(BookResource::canEdit($book));
        $this->assertTrue(BookResource::canDelete($book));
        $this->assertTrue($admin->hasPermissionTo('book_pages.update'));
    }

    public function test_view_only_admin_role_cannot_change_books(): void
    {
        $admin = $this->adminWithRole('admin');
        $book = Book::factory()->create();

        $this->actingAs($admin, 'admin');

        $this->assertTrue(BookResource::canView($book));
        $this->assertFalse(BookResource::canCreate());
        $this->assertFalse(BookResource::canEdit($book));
        $this->assertFalse(BookResource::canDelete($book));
        $this->assertFalse($admin->hasPermissionTo('book_pages.update'));
    }

    public function test_view_only_admin_role_cannot_change_learning_content(): void
    {
        $admin = $this->adminWithRole('admin');
        $subject = Subject::factory()->create();

        $this->actingAs($admin, 'admin');

        $this->assertTrue(SubjectResource::canViewAny());
        $this->assertFalse(SubjectResource::canCreate());
        $this->assertFalse(SubjectResource::canEdit($subject));
        $this->assertFalse(SubjectResource::canDelete($subject));

        Livewire::actingAs($admin, 'admin')
            ->test(ListSubjects::class)
            ->assertOk();
    }

    public function test_only_subjects_appear_in_the_sidebar(): void
    {
        $this->assertTrue(SubjectResource::shouldRegisterNavigation());
        $this->assertFalse(ChapterResource::shouldRegisterNavigation());
        $this->assertFalse(BookResource::shouldRegisterNavigation());
        $this->assertFalse(QuizResource::shouldRegisterNavigation());
        $this->assertFalse(QuestionResource::shouldRegisterNavigation());
    }
}
