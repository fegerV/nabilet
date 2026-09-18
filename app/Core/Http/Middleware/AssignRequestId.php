<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request id and makes it available to logs and error responses.
 *
 * Every log line and every error body carries this id, which is what turns "the
 * customer says checkout failed" into a one-command investigation. The id is
 * accepted from an upstream proxy when present, so a request can be followed across
 * services (nginx -> php-fpm -> queue worker).
 */
final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->sanitise($request->header(self::HEADER)) ?? bin2hex(random_bytes(16));

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        // Available to structured loggers without threading it through every call.
        if (function_exists('context')) {
            context(['request_id' => $requestId]);
        }

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Only accept a plausible opaque id from the client. An attacker-supplied
     * arbitrary string would otherwise be echoed into logs and responses — a
     * log-injection and header-injection vector.
     */
    private function sanitise(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $value) === 1 ? $value : null;
    }
}
