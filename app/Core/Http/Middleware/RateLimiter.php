<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AppError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting middleware (ТЗ §79).
 *
 * Provides configurable rate limits for API endpoints to prevent abuse,
 * brute-force attacks, and resource exhaustion. Uses Laravel's cache-backed
 * rate limiter with customizable keys and limits.
 */
class RateLimiter
{
    public function __construct(
        private readonly LaravelRateLimiter $limiter
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $limit = 'api'): Response
    {
        if ($this->limiter->tooManyAttempts($this->resolveSignature($request, $limit), 1)) {
            $retryAfter = $this->limiter->availableIn($this->resolveSignature($request, $limit));

            // Built from `AppError` rather than hand-rolled so the body cannot drift
            // from the §66 envelope. `retry_after` belongs in `details`, not beside
            // `code`/`message` — the contract reserves those two keys and puts
            // everything else under `details`, so a client can read the error
            // generically without special-casing this middleware.
            $error = new AppError(
                'Too many requests. Please try again later.',
                'TOO_MANY_REQUESTS',
                429,
                ['retry_after' => $retryAfter],
            );

            $requestId = (string) ($request->attributes->get('request_id') ?? '');

            return response()->json($error->toResponse($requestId), 429, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $this->getLimit($limit),
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $this->limiter->hit($this->resolveSignature($request, $limit), $this->getDecay($limit));

        $response = $next($request);

        // Add rate limit headers to response
        $remaining = $this->limiter->retriesLeft($this->resolveSignature($request, $limit), 1);
        $response->headers->set('X-RateLimit-Limit', (string) $this->getLimit($limit));
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining - 1));

        return $response;
    }

    /**
     * Resolve the rate limiter signature for the request.
     */
    protected function resolveSignature(Request $request, string $limit): string
    {
        $ip = $request->ip();
        return sha1($limit . ':' . $ip);
    }

    /**
     * Get the maximum number of attempts for a given limit.
     */
    protected function getLimit(string $limit): int
    {
        return match ($limit) {
            'api' => 60,           // 60 requests per minute for general API
            'auth' => 5,           // 5 attempts per minute for auth endpoints
            'upload' => 10,        // 10 uploads per minute
            'search' => 30,        // 30 searches per minute
            default => 60,
        };
    }

    /**
     * Get the decay time in seconds for a given limit.
     */
    protected function getDecay(string $limit): int
    {
        return match ($limit) {
            'api' => 60,
            'auth' => 60,
            'upload' => 60,
            'search' => 60,
            default => 60,
        };
    }
}
