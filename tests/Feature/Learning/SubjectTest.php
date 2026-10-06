<?php

namespace Tests\Feature\Learning;

use App\Enums\ContentStatus;
use App\Filament\Resources\SubjectResource;
use App\Filament\Resources\SubjectResource\Pages\CreateSubject;
use App\Filament\Resources\SubjectResource\Pages\EditSubject;
use App\Filament\Resources\SubjectResource\Pages\ListSubjects;
use App\Filament\Resources\SubjectResource\Pages\ViewSubject;
use App\Filament\Resources\SubjectResource\RelationManagers\BooksRelationManager;
use App\Filament\Resources\SubjectResource\RelationManagers\ChaptersRelationManager;
use App\Models\Chapter;
use App\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

class SubjectTest extends LearningTestCase
{
    public function test_subject_can_be_created(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreateSubject::class)
            ->fillForm([
                'name' => 'Anatomy',
                'status' => ContentStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('subjects', [
            'name' => 'Anatomy',
            'status' => ContentStatus::Published->value,
        ]);
    }

    public function test_subject_name_is_required(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreateSubject::class)
            ->fillForm(['name' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_subject_has_no_image_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('subjects', 'image'),
            'subjects must not store a raw image path column.',
        );
    }

    public function test_subject_image_can_be_uploaded_to_the_media_collection(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditSubject::class, ['record' => $subject->getRouteKey()])
            ->fillForm(['subject_image' => UploadedFile::fake()->image('anatomy.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $subject->getMedia(Subject::IMAGE_COLLECTION)->count());
        $this->assertNotNull($subject->refresh()->imageUrl());
    }

    public function test_subject_image_replacement_does_not_accumulate_media(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditSubject::class, ['record' => $subject->getRouteKey()])
            ->fillForm(['subject_image' => UploadedFile::fake()->image('first.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $firstMediaId = $subject->refresh()->getFirstMedia(Subject::IMAGE_COLLECTION)->id;

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditSubject::class, ['record' => $subject->getRouteKey()])
            ->fillForm(['subject_image' => UploadedFile::fake()->image('second.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $subject->refresh()->unsetRelation('media');

        $this->assertSame(1, $subject->getMedia(Subject::IMAGE_COLLECTION)->count());
        $this->assertNotSame($firstMediaId, $subject->getFirstMedia(Subject::IMAGE_COLLECTION)->id);
    }

    public function test_invalid_subject_image_is_rejected(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditSubject::class, ['record' => $subject->getRouteKey()])
            ->fillForm(['subject_image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('save')
            ->assertHasFormErrors(['subject_image']);

        $this->assertSame(0, $subject->refresh()->getMedia(Subject::IMAGE_COLLECTION)->count());
    }

    public function test_subject_list_shows_chapter_count_and_status(): void
    {
        $subject = Subject::factory()->create(['name' => 'Physiology', 'status' => ContentStatus::Published]);
        Chapter::factory()->count(3)->create(['subject_id' => $subject->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubjects::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$subject])
            ->assertTableColumnStateSet('chapters_count', 3, $subject)
            ->assertTableColumnStateSet('status', ContentStatus::Published, $subject);
    }

    public function test_subject_list_students_count_is_a_zero_placeholder(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubjects::class)
            ->assertOk()
            ->assertTableColumnStateSet('students_count', 0, $subject);

        $this->assertSame(0, $subject->studentsCount());
    }

    public function test_subject_list_can_be_searched_by_name_and_filtered_by_status(): void
    {
        $published = Subject::factory()->create(['name' => 'Cardiology', 'status' => ContentStatus::Published]);
        $draft = Subject::factory()->create(['name' => 'Neurology', 'status' => ContentStatus::Draft]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubjects::class)
            ->searchTable('Cardiology')
            ->assertCanSeeTableRecords([$published])
            ->assertCanNotSeeTableRecords([$draft])
            ->searchTable(null)
            ->filterTable('status', ContentStatus::Draft->value)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$published]);
    }

    public function test_subject_list_supports_the_soft_delete_filter(): void
    {
        $subject = Subject::factory()->create();
        $subject->delete();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubjects::class)
            ->assertCanNotSeeTableRecords([$subject])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$subject]);
    }

    public function test_subject_view_loads_with_its_overview(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('subject-overview');
    }

    public function test_subject_view_renders_three_workspace_tabs(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists('relationManagerTabs', 'content')
            ->assertSchemaComponentExists('relationManagerTabs.'.ViewSubject::TAB_CHAPTERS, 'content')
            ->assertSchemaComponentExists('relationManagerTabs.'.ViewSubject::TAB_BOOKS, 'content')
            ->assertSchemaComponentExists('relationManagerTabs.'.ViewSubject::TAB_STUDENTS_PROGRESS, 'content')
            ->assertSee(__('admin.learning.chapters_section'))
            ->assertSee(__('admin.learning.books_section'))
            ->assertSee(__('admin.learning.students_progress_section'));
    }

    public function test_the_chapters_tab_holds_the_chapters_table(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists(
                'relationManagerTabs.'.ViewSubject::TAB_CHAPTERS.'.'.ChaptersRelationManager::class,
                'content',
            );
    }

    public function test_the_books_tab_holds_the_books_table_and_is_no_longer_a_placeholder(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists(
                'relationManagerTabs.'.ViewSubject::TAB_BOOKS.'.'.BooksRelationManager::class,
                'content',
            )
            ->assertSchemaComponentDoesNotExist(
                'relationManagerTabs.'.ViewSubject::TAB_BOOKS.'.books-placeholder',
                'content',
            );
    }

    public function test_the_students_progress_tab_shows_only_a_placeholder(): void
    {
        $subject = Subject::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk()
            ->assertSchemaComponentExists(
                'relationManagerTabs.'.ViewSubject::TAB_STUDENTS_PROGRESS.'.students-progress-placeholder',
                'content',
            )
            // Only the active tab's content is rendered.
            ->set('activeRelationManager', ViewSubject::TAB_STUDENTS_PROGRESS)
            ->assertSee(__('admin.learning.students_progress_placeholder_heading'))
            ->assertSee(__('admin.learning.students_progress_placeholder_description'));
    }

    public function test_the_chapters_tab_is_the_default_and_the_other_tabs_stay_selectable(): void
    {
        $subject = Subject::factory()->create();

        $page = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewSubject::class, ['record' => $subject->getRouteKey()])
            ->assertOk();

        $this->assertSame(ViewSubject::TAB_CHAPTERS, $page->instance()->activeRelationManager);

        $page->set('activeRelationManager', ViewSubject::TAB_BOOKS)
            ->assertOk();

        $this->assertSame(ViewSubject::TAB_BOOKS, $page->instance()->activeRelationManager);

        $page->set('activeRelationManager', 'not-a-tab')
            ->assertOk();

        $this->assertSame(ViewSubject::TAB_CHAPTERS, $page->instance()->activeRelationManager);
    }

    public function test_subject_view_registers_the_chapters_relation_manager(): void
    {
        $this->assertArrayHasKey('chapters', SubjectResource::getRelations());
    }

    public function test_subject_view_registers_the_books_relation_manager(): void
    {
        $this->assertArrayHasKey('books', SubjectResource::getRelations());
    }

    /**
     * Study/Progress is still out of scope: books now have a table, students
     * progress does not.
     */
    public function test_the_students_progress_table_does_not_exist(): void
    {
        $this->assertTrue(Schema::hasTable('books'));
        $this->assertFalse(Schema::hasTable('student_subject_progress'));
    }
}
