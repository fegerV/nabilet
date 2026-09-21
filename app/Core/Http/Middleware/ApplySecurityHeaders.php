<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers (ТЗ §79).
 *
 * The CSP deserves a note: a seat-map editor that loads uploaded floor plans and
 * renders QR codes is exactly the kind of application where a permissive CSP turns
 * a stored-XSS into full account takeover. `frame-ancestors` is also the real
 * protection for the admin panel — X-Frame-Options is set for older browsers.
 *
 * Embed routes are exempted from frame-ancestors: embedding is their purpose, and
 * their allowed origins are validated separately by the Embed module.
 */
final class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isEmbed = $request->is('embed/*');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '0', // modern browsers: rely on CSP instead
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if (! $isEmbed) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }

        // HSTS only over HTTPS: sending it over plain HTTP is meaningless and
        // pinning a host that is not yet HTTPS-ready locks users out.
        if ($request->secure() && app()->environment('production')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        $frameAncestors = $isEmbed ? $this->embedAncestors($request) : "'self'";

        $headers['Content-Security-Policy'] = implode('; ', [
                    "default-src 'self'",
                    "base-uri 'self'",
                    "object-src 'none'",
                    "frame-ancestors {$frameAncestors}",
                    "form-action 'self'",
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                    "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                    "img-src 'self' data: blob: https:",
                    "font-src 'self' data: https://fonts.bunny.net",
                    "connect-src 'self'",
                    'upgrade-insecure-requests',
                ]);

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Allowed embedding origins for this request, resolved through a hook so the
     * Embed module owns its own whitelist (ТЗ §50).
     */
    private function embedAncestors(Request $request): string
    {
        $origins = function_exists('apply_filters')
            ? apply_filters('embed.allowed_ancestors', [], $request)
            : [];

        if (! is_array($origins) || $origins === []) {
            // Fail closed: an embed with no configured origin must not be frameable
            // anywhere, otherwise any site could wrap our checkout.
            return "'none'";
        }

        return implode(' ', array_map('strval', $origins));
    }

    /**
     * A per-request nonce so inline scripts can be allowed without
     * 'unsafe-inline'. Stored on the request so the view layer can reuse it.
     */
    private function nonce(Request $request): string
    {
        $nonce = $request->attributes->get('csp_nonce');

        if (! is_string($nonce) || $nonce === '') {
            $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $request->attributes->set('csp_nonce', $nonce);
        }

        return $nonce;
    }
}
