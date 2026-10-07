<?php

namespace Tests\Feature\ReportCases;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Filament\Resources\ReportCaseResource;
use App\Filament\Resources\ReportCaseResource\Pages\CreateReportCase;
use App\Filament\Resources\ReportCaseResource\Pages\EditReportCase;
use App\Filament\Resources\ReportCaseResource\Pages\ListReportCases;
use App\Models\Admin;
use App\Models\ReportCase;
use App\Models\User;
use Database\Factories\ReportCaseFactory;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportCaseTest extends TestCase
{
    use RefreshDatabase;

    private string $studentUrl = '/api/student/report-cases';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('filament_public');

        $this->seed(RolesPermissionsSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function adminWithRole(string $roleName): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    private function student(): User
    {
        return User::factory()->create(['status' => AccountStatus::Active]);
    }

    // ─── Admin ──────────────────────────────────────────────────────────────

    public function test_an_admin_can_create_a_report_case(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreateReportCase::class)
            ->fillForm([
                'title' => 'Acute Appendicitis',
                'description' => 'A 24-year-old presenting with right iliac fossa pain.',
                'source' => 'Zmedix Clinical Board',
                'report_case_pdf' => [ReportCaseFactory::fakePdf('appendicitis.pdf')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $reportCase = ReportCase::query()->where('title', 'Acute Appendicitis')->sole();

        $this->assertSame('Zmedix Clinical Board', $reportCase->source);
        $this->assertTrue($reportCase->is_active);
        $this->assertTrue($reportCase->hasPdf());
        $this->assertSame(
            ReportCase::PDF_COLLECTION,
            $reportCase->getFirstMedia(ReportCase::PDF_COLLECTION)->collection_name,
        );
        $this->assertSame('application/pdf', $reportCase->getFirstMedia(ReportCase::PDF_COLLECTION)->mime_type);
    }

    public function test_creating_a_report_case_requires_its_fields_and_a_pdf(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreateReportCase::class)
            ->fillForm([
                'title' => null,
                'description' => null,
                'source' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'title' => 'required',
                'description' => 'required',
                'source' => 'required',
                'report_case_pdf' => 'required',
            ]);

        $this->assertSame(0, ReportCase::query()->count());
    }

    public function test_an_admin_can_update_a_report_case_without_replacing_the_pdf(): void
    {
        $reportCase = ReportCase::factory()->withPdfFile('original.pdf')->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditReportCase::class, ['record' => $reportCase->getRouteKey()])
            ->fillForm([
                'title' => 'Updated Title',
                'source' => 'Updated Source',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reportCase->refresh()->unsetRelation('media');

        $this->assertSame('Updated Title', $reportCase->title);
        $this->assertSame('Updated Source', $reportCase->source);
        $this->assertSame('original.pdf', $reportCase->pdfFileName());
    }

    public function test_an_admin_can_replace_the_pdf_of_a_report_case(): void
    {
        $reportCase = ReportCase::factory()->withPdfFile('original.pdf')->create();
        $originalMediaId = $reportCase->getFirstMedia(ReportCase::PDF_COLLECTION)->id;

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditReportCase::class, ['record' => $reportCase->getRouteKey()])
            ->fillForm(['report_case_pdf' => [ReportCaseFactory::fakePdf('replacement.pdf')]])
            ->call('save')
            ->assertHasNoFormErrors();

        $reportCase->refresh()->unsetRelation('media');

        // Single-file collection: the new upload replaces the old one rather
        // than accumulating alongside it.
        $this->assertSame(1, $reportCase->getMedia(ReportCase::PDF_COLLECTION)->count());
        $this->assertNotSame($originalMediaId, $reportCase->getFirstMedia(ReportCase::PDF_COLLECTION)->id);
    }

    public function test_an_admin_can_delete_a_report_case(): void
    {
        $reportCase = ReportCase::factory()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListReportCases::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($reportCase));

        $this->assertDatabaseMissing('report_cases', ['id' => $reportCase->id]);
    }

    public function test_an_admin_can_deactivate_and_reactivate_a_report_case(): void
    {
        $reportCase = ReportCase::factory()->create();

        $table = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListReportCases::class);

        $table->callAction(TestAction::make('toggleActive')->table($reportCase))
            ->assertHasNoActionErrors();

        $this->assertFalse($reportCase->refresh()->is_active);

        $table->callAction(TestAction::make('toggleActive')->table($reportCase))
            ->assertHasNoActionErrors();

        $this->assertTrue($reportCase->refresh()->is_active);
    }

    public function test_the_admin_role_can_manage_report_cases(): void
    {
        $admin = $this->adminWithRole('admin');
        $reportCase = ReportCase::factory()->create();

        $this->actingAs($admin, 'admin');

        $this->assertTrue(ReportCaseResource::canViewAny());
        $this->assertTrue(ReportCaseResource::canCreate());
        $this->assertTrue(ReportCaseResource::canEdit($reportCase));
        $this->assertTrue(ReportCaseResource::canDelete($reportCase));
    }

    public function test_the_admin_role_can_deactivate_a_report_case(): void
    {
        $reportCase = ReportCase::factory()->create();

        Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(ListReportCases::class)
            ->callAction(TestAction::make('toggleActive')->table($reportCase))
            ->assertHasNoActionErrors();

        $this->assertFalse($reportCase->refresh()->is_active);
    }

    public function test_report_case_permissions_are_seeded(): void
    {
        $seeded = Permission::query()->where('guard_name', 'admin')->pluck('name')->all();

        foreach (['view', 'create', 'update', 'delete'] as $ability) {
            $this->assertContains("report_cases.{$ability}", $seeded);
        }
    }

    public function test_an_admin_without_report_case_permissions_is_locked_out(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'no_report_cases_role', 'guard_name' => 'admin'],
            ['display_name_ar' => 'بدون حالات', 'display_name_en' => 'No Report Cases'],
        );
        $role->syncPermissions(['content.view']);

        $admin = $this->adminWithRole('no_report_cases_role');
        $reportCase = ReportCase::factory()->create();

        $this->actingAs($admin, 'admin');

        $this->assertFalse(ReportCaseResource::canViewAny());
        $this->assertFalse(ReportCaseResource::canCreate());
        $this->assertFalse(ReportCaseResource::canEdit($reportCase));
        $this->assertFalse(ReportCaseResource::canDelete($reportCase));

        // canAccess() is what gates both the route and the sidebar entry, so an
        // admin who cannot view the records never sees them in navigation.
        $this->assertFalse(ReportCaseResource::canAccess());
    }

    public function test_an_admin_who_cannot_edit_is_not_offered_the_activate_action(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'report_cases_viewer_role', 'guard_name' => 'admin'],
            ['display_name_ar' => 'قارئ الحالات', 'display_name_en' => 'Report Cases Viewer'],
        );
        $role->syncPermissions(['report_cases.view']);

        $reportCase = ReportCase::factory()->create();

        // View-only: the table renders, but the state-changing action and the
        // edit/delete actions are all withheld by the policy.
        Livewire::actingAs($this->adminWithRole('report_cases_viewer_role'), 'admin')
            ->test(ListReportCases::class)
            ->assertOk()
            ->assertTableActionHidden('toggleActive', record: $reportCase)
            ->assertTableActionHidden('edit', record: $reportCase)
            ->assertTableActionHidden('delete', record: $reportCase);

        $this->assertTrue($reportCase->refresh()->is_active);
    }

    public function test_the_pdf_rules_come_from_the_shared_media_config(): void
    {
        $this->assertSame(['application/pdf'], ReportCaseResource::pdfMimeTypes());
        $this->assertSame((int) config('media.max_document_size_kb'), ReportCaseResource::pdfMaxSizeKb());
    }

    // ─── Student API ────────────────────────────────────────────────────────

    public function test_a_student_sees_active_report_cases(): void
    {
        ReportCase::factory()->count(2)->create();

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studentUrl);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'title', 'description', 'source', 'pdf_url']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links',
            ]);

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_a_student_never_sees_an_inactive_report_case(): void
    {
        $active = ReportCase::factory()->create(['title' => 'Visible Case']);
        $inactive = ReportCase::factory()->inactive()->create(['title' => 'Hidden Case']);

        Sanctum::actingAs($this->student());

        $response = $this->getJson($this->studentUrl)->assertOk();

        $this->assertSame([$active->id], array_column($response->json('data'), 'id'));
        $response->assertJsonMissing(['id' => $inactive->id]);
        $response->assertDontSee('Hidden Case');
    }

    public function test_the_student_response_includes_an_accessible_pdf_url(): void
    {
        ReportCase::factory()->withPdfFile('case.pdf')->create();

        Sanctum::actingAs($this->student());

        $pdfUrl = $this->getJson($this->studentUrl)
            ->assertOk()
            ->json('data.0.pdf_url');

        $this->assertNotNull($pdfUrl);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $pdfUrl);
        $this->assertStringContainsString('case.pdf', $pdfUrl);
    }

    public function test_the_student_endpoint_rejects_a_guest(): void
    {
        ReportCase::factory()->create();

        $this->getJson($this->studentUrl)->assertUnauthorized();
    }
}
