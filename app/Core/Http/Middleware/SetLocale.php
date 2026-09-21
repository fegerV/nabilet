<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the interface locale for the request (ТЗ §39).
 *
 * Priority: explicit user preference -> session -> Accept-Language -> fallback.
 * The resolved locale is published through a hook so the Localization module can
 * plug in translation loading without the kernel knowing about it.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        if (function_exists('do_action')) {
            do_action('locale.resolved', $locale, $request);
        }

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $locales = config('app.locales', ['ru']);
        $supported = is_array($locales)
            ? array_filter($locales, 'is_string')
            : array_filter(explode(',', (string) $locales));
        $supported = $supported === [] ? ['ru'] : $supported;
        $fallback = (string) config('app.fallback_locale', 'ru');

        $user = $request->user();
        if ($user !== null && ! empty($user->locale) && in_array($user->locale, $supported, true)) {
            return (string) $user->locale;
        }

        if ($request->hasSession()) {
            $sessionLocale = $request->session()->get('locale');
            if (is_string($sessionLocale) && in_array($sessionLocale, $supported, true)) {
                return $sessionLocale;
            }
        }

        // Accept-Language is a preference list with quality values; take the first
        // supported entry rather than the first entry outright.
        foreach ($request->getLanguages() as $candidate) {
            $short = substr($candidate, 0, 2);
            if (in_array($short, $supported, true)) {
                return $short;
            }
        }

        return $fallback;
    }
}
