<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AccessManagementPage;
use App\Filament\Pages\Auth\EditProfile;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Setting;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->domain(config('app.admin_domain'))
            ->path('')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandLogo(fn (): ?string => $this->siteLogoUrl())
            ->login()
            ->passwordReset()
            ->authPasswordBroker('admins')
            ->profile(EditProfile::class, isSimple: false)
            ->authGuard('admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render("@livewire('admin-locale-switcher')"),
            )
            ->renderHook(
                TablesRenderHook::TOOLBAR_SEARCH_AFTER,
                fn (): View => view(
                    'filament.components.table-per-page-select',
                    ['pageOptions' => AccessManagementPage::getAdminTablePageOptions()],
                ),
                scopes: AccessManagementPage::class,
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetAdminLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function siteLogoUrl(): ?string
    {
        $path = Setting::query()->where('key', 'site_logo')->value('value');

        return filled($path) ? Storage::disk('public')->url($path) : null;
    }
}
