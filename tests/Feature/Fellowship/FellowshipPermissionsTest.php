<?php

namespace Tests\Feature\Fellowship;

use App\Filament\Pages\FellowshipSettingsPage;
use App\Filament\Resources\FellowshipResource;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\FellowshipImport;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Permission;

/**
 * Authorization for the Fellowship module, through the application's existing
 * permission families and policies.
 */
class FellowshipPermissionsTest extends FellowshipTestCase
{
    public function test_fellowship_permissions_are_seeded(): void
    {
        $seeded = Permission::query()->where('guard_name', 'admin')->pluck('name')->all();

        foreach (['fellowships', 'fellowship_questions', 'fellowship_filters'] as $family) {
            foreach (['view', 'create', 'update', 'delete'] as $ability) {
                $this->assertContains("{$family}.{$ability}", $seeded);
            }
        }
    }

    public function test_seeding_permissions_again_is_idempotent(): void
    {
        $before = Permission::query()->where('guard_name', 'admin')->count();

        $this->seed(RolesPermissionsSeeder::class);

        $this->assertSame($before, Permission::query()->where('guard_name', 'admin')->count());
    }

    public function test_a_content_manager_can_manage_the_whole_fellowship_tree(): void
    {
        $this->actingAs($this->adminWithRole('content_manager'), 'admin');

        $fellowship = $this->fellowship();

        $this->assertTrue(FellowshipResource::canViewAny());
        $this->assertTrue(FellowshipResource::canCreate());
        $this->assertTrue(FellowshipResource::canEdit($fellowship));
        $this->assertTrue(FellowshipResource::canDelete($fellowship));
        $this->assertTrue(ImportFellowshipQuestions::canImport());
    }

    public function test_the_admin_role_can_read_but_not_change_the_fellowship_tree(): void
    {
        $this->actingAs($this->adminWithRole('admin'), 'admin');

        $fellowship = $this->fellowship();

        $this->assertTrue(FellowshipResource::canViewAny());
        $this->assertFalse(FellowshipResource::canCreate());
        $this->assertFalse(FellowshipResource::canEdit($fellowship));
        $this->assertFalse(FellowshipResource::canDelete($fellowship));
        $this->assertFalse(ImportFellowshipQuestions::canImport(), 'Importing writes questions.');
    }

    public function test_an_admin_without_fellowship_permissions_sees_nothing_of_the_module(): void
    {
        // Holds the Subject tree outright and nothing of the Fellowship one,
        // which is the separation the two permission families exist for.
        $this->actingAs($this->adminWithPermissions([
            'subjects.view',
            'subjects.create',
            'questions.create',
        ]), 'admin');

        $this->assertFalse(FellowshipResource::canViewAny());
        $this->assertFalse(ImportFellowshipQuestions::canImport());
        $this->assertFalse(FellowshipSettingsPage::canAccess());
    }

    public function test_the_super_admin_holds_every_fellowship_permission(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $fellowship = $this->fellowship();

        $this->assertTrue(FellowshipResource::canViewAny());
        $this->assertTrue(FellowshipResource::canCreate());
        $this->assertTrue(FellowshipResource::canDelete($fellowship));
        $this->assertTrue(ImportFellowshipQuestions::canImport());
        $this->assertTrue(FellowshipSettingsPage::canAccess());
    }

    public function test_question_and_filter_policies_answer_to_their_own_families(): void
    {
        // May delete a question but not the classification questions are filed
        // under: the two are separate families on purpose.
        $admin = $this->adminWithPermissions([
            'fellowships.view',
            'fellowship_questions.view',
            'fellowship_questions.delete',
            'fellowship_filters.view',
        ]);

        $this->actingAs($admin, 'admin');

        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);
        $filter = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->assertTrue($admin->can('delete', $question));
        $this->assertFalse($admin->can('delete', $filter));
    }

    public function test_import_history_is_never_deletable(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');

        $import = FellowshipImport::factory()->create([
            'fellowship_id' => $this->fellowship()->getKey(),
        ]);

        $this->assertTrue($admin->can('view', $import));
        $this->assertFalse($admin->can('delete', $import), 'An import record is an audit trail.');
    }
}
