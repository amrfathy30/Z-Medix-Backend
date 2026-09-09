<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    private const DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('admin_locale') : null;

        if ($this->isSupported($sessionLocale)) {
            return $sessionLocale;
        }

        /** @var Admin|null $admin */
        $admin = $request->user('admin');

        return $admin !== null && $this->isSupported($admin->locale)
            ? $admin->locale
            : self::DEFAULT_LOCALE;
    }

    private function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, strict: true);
    }
}
