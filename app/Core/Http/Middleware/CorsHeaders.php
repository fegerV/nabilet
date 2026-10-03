<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS headers middleware (ТЗ §79).
 *
 * Manages Cross-Origin Resource Sharing headers with configurable
 * allowed origins, methods, and headers. Provides fine-grained control
 * over cross-origin access beyond Laravel's default CORS configuration.
 */
class CorsHeaders
{
    /**
     * Default cache duration for preflight requests (in seconds).
     */
    private const MAX_AGE = 3600;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS request
        if ($request->method() === 'OPTIONS') {
            return $this->handlePreflight($request);
        }

        $response = $next($request);

        // Add CORS headers to response
        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Handle preflight OPTIONS request.
     */
    protected function handlePreflight(Request $request): Response
    {
        $response = response('', 204);
        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Add CORS headers to response.
     */
    protected function addCorsHeaders(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin && $this->isAllowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        if ($request->method() === 'OPTIONS') {
            $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->getAllowedMethods()));
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->getAllowedHeaders()));
            $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
        }

        $response->headers->set('Access-Control-Expose-Headers', implode(', ', $this->getExposedHeaders()));

        return $response;
    }

    /**
     * Check if origin is allowed.
     */
    protected function isAllowedOrigin(string $origin): bool
    {
        $allowedOrigins = $this->getAllowedOrigins();

        if (empty($allowedOrigins)) {
            return false;
        }

        foreach ($allowedOrigins as $allowed) {
            if ($allowed === '*') {
                return true;
            }

            if ($allowed === $origin) {
                return true;
            }

            // Support wildcard subdomains
            if (str_starts_with($allowed, '*.')) {
                $baseDomain = substr($allowed, 2);
                if (str_ends_with($origin, $baseDomain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get allowed origins from config.
     *
     * @return array<int, string>
     */
    protected function getAllowedOrigins(): array
    {
        $origins = config('cors.allowed_origins', []);
        return is_array($origins) ? $origins : [];
    }

    /**
     * Get allowed HTTP methods.
     *
     * @return array<int, string>
     */
    protected function getAllowedMethods(): array
    {
        return config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
    }

    /**
     * Get allowed headers.
     *
     * @return array<int, string>
     */
    protected function getAllowedHeaders(): array
    {
        return config('cors.allowed_headers', [
            'Content-Type',
            'Authorization',
            'Idempotency-Key',
            'X-Request-Id',
            'X-Locale',
            'X-CSRF-TOKEN',
        ]);
    }

    /**
     * Get exposed headers.
     *
     * @return array<int, string>
     */
    protected function getExposedHeaders(): array
    {
        return config('cors.exposed_headers', ['X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']);
    }
}
