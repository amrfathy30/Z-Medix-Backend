<?php

namespace App\Http\Middleware;

use App\Support\Localization\PublicLocales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetPublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // ?lang= query parameter takes priority
        $lang = $request->query('lang');

        if (PublicLocales::supports($lang)) {
            return $lang;
        }

        // Accept-Language header as fallback
        $header = $request->header('Accept-Language', '');

        if (is_string($header)) {
            // Parse primary language tag (e.g. "ar-EG,ar;q=0.9" → "ar")
            $primary = strtolower(explode(',', $header)[0]);
            $primary = strtolower(explode('-', $primary)[0]);
            $primary = trim($primary);

            if (PublicLocales::supports($primary)) {
                return $primary;
            }
        }

        return PublicLocales::default();
    }
}
