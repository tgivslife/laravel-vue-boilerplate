<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale from the client's `Accept-Language` header and
 * reflects the resolved locale back via the `Content-Language` response header.
 *
 * Negotiation is restricted to `config('app.supported_locales')` (falling back to
 * `config('app.locale')` when unset) so a request can never activate a locale
 * without translation strings; when no supported locale matches, `config('app.fallback_locale')`
 * is used instead.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_map(
                static fn(mixed $locale): string => trim((string) $locale),
                (array) config('app.supported_locales', [config('app.locale')]),
            )
                |> array_filter(...)
                |> array_values(...);

        $preferredLocale = $request->getPreferredLanguage($supportedLocales);
        $resolvedLocale = is_string($preferredLocale) && $preferredLocale !== ''
            ? $preferredLocale
            : (string) config('app.fallback_locale', 'en');

        App::setLocale($resolvedLocale);

        $response = $next($request);
        $response->headers->set('Content-Language', App::currentLocale());

        return $response;
    }
}
