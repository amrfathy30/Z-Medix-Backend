<?php

namespace Tests\Feature\Auth\Admin;

use App\Filament\Pages\AccessManagementPage;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\WebsiteContentPage;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\User;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_authentication_pages_are_english_despite_arabic_preferences(): void
    {
        app()->setLocale('ar');

        $this->withSession(['admin_locale' => 'ar'])
            ->withHeader('Accept-Language', 'ar')
            ->get(route('filament.admin.auth.login', ['lang' => 'ar']))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Sign in')
            ->assertDontSee('switchLocale');

        $this->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    public function test_stored_and_session_arabic_preferences_cannot_change_dashboard_language(): void
    {
        foreach (['admins', 'roles', 'content', 'settings', 'settings.integrations', 'marketing.pixels', 'reports', 'subjects', 'chapters', 'chapter_pages', 'books', 'book_pages', 'quizzes', 'questions', 'fellowships', 'fellowship_questions', 'fellowship_filters', 'report_cases', 'plans', 'subscriptions', 'contact_messages'] as $family) {
            Permission::findOrCreate($family.'.view', 'admin');
        }
        $admin = Admin::factory()->create(['locale' => 'ar']);
        app()->setLocale('ar');

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_locale' => 'ar'])
            ->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Dashboard')
            ->assertDontSee('switchLocale')
            ->assertDontSee('admin-locale-switcher');

        $this->assertSame('ar', $admin->fresh()->locale);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_panel_locale_middleware_is_persistent_and_scoped_to_admin_routes(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertContains(SetAdminLocale::class, $panel->getMiddleware());
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
        $this->assertContains(SetAdminLocale::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
        $this->assertNotContains(SetAdminLocale::class, app('router')->getMiddlewareGroups()['api']);

        app()->setLocale('ar');
        app(SetAdminLocale::class)->handle(Request::create('/'), function () {
            $this->assertSame('en', app()->getLocale());

            return response('ok');
        });
    }

    public function test_profile_has_no_interface_language_field_and_preserves_stored_preference(): void
    {
        $admin = Admin::factory()->create(['locale' => 'ar']);

        Livewire::actingAs($admin, 'admin')->test(EditProfile::class)
            ->assertFormFieldDoesNotExist('locale')
            ->fillForm(['first_name' => 'English Admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ar', $admin->fresh()->locale);
        $this->assertSame('English Admin', $admin->first_name);
    }

    public function test_suspended_admin_still_cannot_access_dashboard(): void
    {
        $this->actingAs(Admin::factory()->suspended()->create(), 'admin')
            ->get(route('filament.admin.pages.dashboard'))
            ->assertForbidden();
    }

    public function test_livewire_validation_from_an_old_arabic_snapshot_stays_english(): void
    {
        $response = $this->get(route('filament.admin.auth.login'))->assertOk();
        preg_match('/wire:snapshot="([^\"]+)"/', $response->getContent(), $matches);
        $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
        $snapshot['memo']['locale'] = 'ar';
        unset($snapshot['checksum']);
        $snapshot['checksum'] = Checksum::generate($snapshot);
        app()->setLocale('ar');

        $response = $this->withSession(['admin_locale' => 'ar'])
            ->withHeader('X-Livewire', 'true')
            ->postJson(Livewire::getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($snapshot),
                    'updates' => [],
                    'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
                ]],
            ])->assertOk();

        $updated = json_decode($response->json('components.0.snapshot'), true);
        $this->assertSame('en', $updated['memo']['locale']);
        $this->assertStringContainsString('required', implode(' ', $updated['memo']['errors']['data.email']));
    }

    public function test_registered_resource_labels_and_navigation_are_english(): void
    {
        app(SetAdminLocale::class)->handle(Request::create('/'), function () {
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                foreach (['getNavigationLabel', 'getNavigationGroup', 'getModelLabel', 'getPluralModelLabel'] as $method) {
                    $label = $resource::$method();
                    if ($label !== null) {
                        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $label, $resource.'::'.$method);
                        $this->assertStringNotContainsString('admin.', $label);
                    }
                }
            }

            return response('ok');
        });
    }

    public function test_admin_can_still_sign_in_with_an_arabic_stored_preference(): void
    {
        $admin = Admin::factory()->create(['locale' => 'ar']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_student_language_negotiation_remains_independent_of_admin_locale(): void
    {
        Plan::factory()->create(['name' => ['en' => 'Study Plan', 'ar' => 'خطة الدراسة']]);
        $this->get(route('filament.admin.auth.login'))->assertOk();
        Sanctum::actingAs(User::factory()->create());

        $this->withHeader('Accept-Language', 'ar-EG,ar;q=0.9')
            ->getJson('/api/student/plans')->assertOk()
            ->assertJsonPath('data.0.name', 'خطة الدراسة');
        $this->getJson('/api/student/plans?lang=en')->assertOk()
            ->assertJsonPath('data.0.name', 'Study Plan');
    }

    public function test_role_permission_and_cms_definition_labels_are_english(): void
    {
        $role = Role::create(['name' => 'content_editor', 'guard_name' => 'admin', 'display_name_en' => 'Content Editor', 'display_name_ar' => 'محرر المحتوى']);
        $permission = Permission::create(['name' => 'content.view', 'guard_name' => 'admin', 'display_name_en' => 'View Content', 'display_name_ar' => 'عرض المحتوى']);
        $role->givePermissionTo($permission);
        $admin = Admin::factory()->create(['locale' => 'ar']);
        $admin->assignRole($role);
        $this->actingAs($admin, 'admin');
        app()->setLocale('ar');
        $page = new AccessManagementPage;

        $this->assertSame('Content Editor', $page->getRoleDisplayName($role));
        $this->assertSame('Content Editor', $page->getLocalizedRoleName('content_editor'));
        $this->assertSame('unknown_role', $page->getLocalizedRoleName('unknown_role'));
        $this->assertSame('View Content', $page->getPermissionLabel($permission));
        $this->assertTrue($admin->hasPermissionTo('content.view'));
        $this->assertSame('محرر المحتوى', $role->fresh()->display_name_ar);
        $this->assertSame('عرض المحتوى', $permission->fresh()->display_name_ar);

        $role->display_name_en = null;
        $permission->display_name_en = null;
        $this->assertSame('content_editor', $page->getRoleDisplayName($role));
        $this->assertSame('content.view', $page->getPermissionLabel($permission));

        app()->setLocale('en');
        foreach (app(ContentDefinitionRegistry::class)->allPages() as $definition) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', WebsiteContentPage::pageTitle($definition));
        }
    }

    public function test_admin_interface_translation_keys_have_english_text(): void
    {
        $missing = [];
        $directories = [app_path('Filament'), app_path('Support/Filament'), app_path('Support/Fellowship'), app_path('Support/Content'), resource_path('views/filament')];
        foreach ($directories as $directory) {
            foreach (File::allFiles($directory) as $file) {
                preg_match_all('/(?:__|trans)\(\s*[\'"]([^\'"]+)[\'"]\s*[,)]/', $file->getContents(), $matches);
                foreach ($matches[1] as $key) {
                    if (str_contains($key, '$')) {
                        continue;
                    }

                    if (! app('translator')->hasForLocale($key, 'en')) {
                        $missing[] = $file->getFilename().': '.$key;

                        continue;
                    }
                    $text = app('translator')->get($key, [], 'en');
                    if (is_string($text)) {
                        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text, $key);
                    }
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
