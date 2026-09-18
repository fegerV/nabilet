<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces JSON negotiation on API routes.
 *
 * Without this, a client that omits `Accept: application/json` receives an HTML
 * error page from Laravel's exception handler — which a mobile client then tries to
 * parse as JSON. The Checker app in particular must never have to guess.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
