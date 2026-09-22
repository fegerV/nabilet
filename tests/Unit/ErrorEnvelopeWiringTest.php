<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Guards the HTTP error-handler wiring.
 *
 * ErrorModelTest covers AppError in isolation, but the handler that actually renders it
 * needs the full framework to execute — which this sandbox cannot install (no Composer
 * network access). That gap let a real defect through: `AppError::toProblem()` was
 * renamed to `toResponse()`, and the handler kept calling the old name, so every
 * operational error would have fataled at runtime with "Call to undefined method" while
 * the suite stayed green.
 *
 * The contract is now split across two files, because the rendering logic was extracted
 * into `ApiExceptionRenderer` so it could be exercised without a booted framework:
 *
 *   bootstrap/app.php   must DELEGATE  → `ApiExceptionRenderer::render()`
 *   ApiExceptionRenderer must RENDER   → `AppError::toResponse()`, `application/json`
 *   AppError            must SHAPE     → the nested `error` object
 *
 * Asserting only one half would let the other regress silently — and a bootstrap that
 * stops delegating is precisely the defect this test exists for: the failure falls back
 * to Laravel's own error shape (`{"message":…}`) on every path, which is what the
 * running server actually did before it was fixed.
 */
final class ErrorEnvelopeWiringTest extends TestCase
{
    private string $bootstrap = '';
    private string $renderer = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        $bootstrapPath = $root . '/bootstrap/app.php';
        $rendererPath = $root . '/app/Core/Http/ApiExceptionRenderer.php';

        if (! is_file($bootstrapPath)) {
            $this->fail('bootstrap/app.php not found at ' . $bootstrapPath);
        }

        if (! is_file($rendererPath)) {
            $this->fail('ApiExceptionRenderer.php not found at ' . $rendererPath);
        }

        $this->bootstrap = (string) file_get_contents($bootstrapPath);
        $this->renderer = (string) file_get_contents($rendererPath);
    }

    public function testBootstrapDoesNotUseRfc7807(): void
    {
        foreach ([$this->bootstrap, $this->renderer] as $source) {
            $this->assertFalse(
                str_contains($source, 'toProblem'),
                'the error handler must call AppError::toResponse(), not the removed toProblem()'
            );

            $this->assertFalse(
                str_contains($source, 'problem+json'),
                'the §66 envelope is served as application/json, never application/problem+json'
            );
        }
    }

    /**
     * Half one: the bootstrap must hand JSON failures to the renderer.
     *
     * If this breaks, the app does not fatal — it quietly answers with Laravel's default
     * shape, which is the harder bug to notice. Verified live on the running server: a
     * 404 read `{"message":"No query results for model [Nabilet\\Modules\\…] 999999"}`,
     * leaking the internal namespace.
     */
    public function testBootstrapDelegatesToTheEnvelopeRenderer(): void
    {
        $this->assertTrue(
            str_contains($this->bootstrap, 'ApiExceptionRenderer::render'),
            'bootstrap/app.php must delegate error rendering to ApiExceptionRenderer::render()'
        );

        // HTML surfaces must keep Laravel's own rendering: a JSON envelope in the
        // Filament admin panel or the installer would be a regression.
        $this->assertTrue(
            str_contains($this->bootstrap, "is('api/*')"),
            'the renderer must be limited to API/JSON requests so HTML surfaces keep their error pages'
        );
    }

    /**
     * Half two: the renderer must produce the envelope in the documented wire format.
     */
    public function testRendererProducesTheSection66Envelope(): void
    {
        $this->assertTrue(
            str_contains($this->renderer, 'toResponse('),
            'the operational branch must render AppError::toResponse()'
        );

        $this->assertTrue(
            str_contains($this->renderer, "'Content-Type' => 'application/json'"),
            'error responses must be served as application/json'
        );

        $this->assertTrue(
            str_contains($this->renderer, 'report($e)'),
            'a non-operational error is a bug and must be reported before being redacted'
        );

        $this->assertTrue(
            str_contains($this->renderer, "'INTERNAL_ERROR'"),
            'bugs must be answered with a generic INTERNAL_ERROR, never their real message'
        );
    }

    /**
     * The shape itself lives in AppError — the single source of truth for the envelope.
     */
    public function testAppErrorBuildsTheNestedErrorObject(): void
    {
        $appError = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Core/Errors/AppError.php'
        );

        $this->assertTrue(
            str_contains($appError, "return ['error' => \$error];"),
            'AppError::toResponse() must nest the payload under an `error` key'
        );

        $this->assertTrue(
            str_contains($appError, "'code' => \$this->errorCode"),
            'the internal `errorCode` property must be renamed to `code` on the wire'
        );
    }

    public function testFallbackErrorBodyHasNoRfc7807Members(): void
    {
        foreach ([$this->bootstrap, $this->renderer] as $source) {
            foreach (["'type' =>", "'title' =>", "'detail' =>"] as $member) {
                $this->assertFalse(
                    str_contains($source, $member),
                    'RFC 7807 member ' . $member . ' must not appear in the error handler'
                );
            }
        }
    }

    /**
     * A dangling class reference in the bootstrap is a startup-time fatal, and in a
     * modular monolith it is easy to introduce by removing a middleware.
     */
    public function testEveryReferencedCoreClassExists(): void
    {
        preg_match_all('/\\\\Nabilet\\\\Core\\\\[A-Za-z0-9_\\\\]+::class/', $this->bootstrap, $matches);

        $this->assertTrue($matches[0] !== [], 'expected bootstrap/app.php to reference Core classes');

        $root = dirname(__DIR__, 2);

        foreach (array_unique($matches[0]) as $reference) {
            $class = substr($reference, 0, -strlen('::class'));
            $relative = 'app/Core/' . str_replace('\\', '/', substr($class, strlen('\\Nabilet\\Core\\'))) . '.php';

            $this->assertTrue(
                is_file($root . '/' . $relative),
                'dangling reference in bootstrap/app.php: ' . $class . ' (expected ' . $relative . ')'
            );
        }
    }

    /**
     * The bootstrap imports `ApiExceptionRenderer` by short name. If the import is
     * dropped while the call is kept, PHP resolves it against the global namespace and
     * the handler fatals on the first error — at runtime, not at boot.
     */
    public function testRendererIsImportedIntoTheBootstrap(): void
    {
        // Deliberately namespace-agnostic: the renderer was moved from
        // `Core\Errors` to `Core\Http` because the purity guard correctly refused a
        // framework-aware adapter in the framework-free kernel. Pinning the exact
        // namespace here would have made that a test failure rather than a refactor.
        $this->assertTrue(
            (bool) preg_match('/^use\s+[A-Za-z0-9_\\\\]*\\\\ApiExceptionRenderer;/m', $this->bootstrap),
            'bootstrap/app.php must import ApiExceptionRenderer, or the short-name call resolves globally'
        );
    }
}
