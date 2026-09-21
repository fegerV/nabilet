<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection middleware (ТЗ §79).
 *
 * Validates CSRF tokens for state-changing requests to prevent
 * Cross-Site Request Forgery attacks. Works in conjunction with
 * Laravel's built-in CSRF protection but provides additional
 * control over token validation and error responses.
 */
class CsrfProtection
{
    /**
     * HTTP methods that require CSRF validation.
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * URI patterns exempt from CSRF validation.
     */
    private array $except = [
        'api/*',  // API uses token-based auth
        'up',
        '_debugbar/*',
        '_tt/*',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkipCsrf($request)) {
            return $next($request);
        }

        if ($this->isStateChangingRequest($request) && !$this->hasValidCsrfToken($request)) {
            return response()->json([
                'error' => [
                    'code' => 'CSRF_TOKEN_MISMATCH',
                    'message' => 'CSRF token mismatch. Please refresh the page and try again.',
                ],
            ], 419);
        }

        return $next($request);
    }

    /**
     * Check if the request should skip CSRF validation.
     */
    protected function shouldSkipCsrf(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ($this->except as $pattern) {
            $pattern = trim($pattern, '/');

            if ($pattern === '*') {
                return true;
            }

            if (str_ends_with($pattern, '/*')) {
                $prefix = rtrim($pattern, '/*');
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            } elseif ($path === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the request is a state-changing method.
     */
    protected function isStateChangingRequest(Request $request): bool
    {
        return in_array(strtoupper($request->method()), self::STATE_CHANGING_METHODS, true);
    }

    /**
     * Validate the CSRF token.
     */
    protected function hasValidCsrfToken(Request $request): bool
    {
        $token = $this->getCsrfToken($request);

        if (empty($token)) {
            return false;
        }

        return hash_equals(session('_token', ''), $token);
    }

    /**
     * Get CSRF token from request.
     */
    protected function getCsrfToken(Request $request): ?string
    {
        return $request->input('_token')
            ?? $request->header('X-CSRF-TOKEN')
            ?? $request->header('X-XSRF-TOKEN');
    }
}
