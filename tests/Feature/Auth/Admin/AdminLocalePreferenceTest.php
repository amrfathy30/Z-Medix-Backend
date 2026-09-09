<?php

namespace Tests\Feature\Auth\Admin;

use App\Http\Middleware\SetAdminLocale;
use App\Livewire\AdminLocaleSwitcher;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_locale_column_and_factory_default_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('admins', 'locale'));
        $this->assertSame('ar', Admin::factory()->create()->locale);
    }

    public function test_session_locale_wins_over_stored_locale(): void
    {
        $admin = Admin::factory()->create(['locale' => 'ar']);
        Auth::guard('admin')->login($admin);
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('admin_locale', 'en');

        app(SetAdminLocale::class)->handle($request, fn () => response('ok'));

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('ar', $admin->fresh()->locale);
    }

    public function test_switcher_persists_supported_locale(): void
    {
        $admin = Admin::factory()->create(['locale' => 'ar']);

        Livewire::actingAs($admin, 'admin')->test(AdminLocaleSwitcher::class)
            ->call('switchLocale', 'en');

        $this->assertSame('en', $admin->fresh()->locale);
    }
}
