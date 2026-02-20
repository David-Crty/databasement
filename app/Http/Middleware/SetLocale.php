<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Set the application locale from cookie, browser preference, or default.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, string> $availableLocales */
        $availableLocales = config('app.available_locales', []);
        $localeCodes = array_keys($availableLocales);

        $locale = $this->fromCookie($request, $localeCodes)
            ?? $this->fromBrowser($request, $localeCodes)
            ?? config('app.locale');

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * @param  array<int, string>  $localeCodes
     */
    private function fromCookie(Request $request, array $localeCodes): ?string
    {
        $cookie = $request->cookie('locale');

        if ($cookie && in_array($cookie, $localeCodes, true)) {
            return $cookie;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $localeCodes
     */
    private function fromBrowser(Request $request, array $localeCodes): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if (in_array($language, $localeCodes, true)) {
                return $language;
            }

            $prefix = strtok($language, '_');

            if ($prefix !== false && in_array($prefix, $localeCodes, true)) {
                return $prefix;
            }
        }

        return null;
    }
}
