<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Guards the HTTP error-handler wiring in bootstrap/app.php.
 *
 * ErrorModelTest covers AppError in isolation, but the handler that actually
 * renders it lives in bootstrap/app.php and needs the full framework to execute —
 * which this sandbox cannot install (no Composer network access). That gap let a
 * real defect through: AppError::toProblem() was renamed to toResponse(), and
 * bootstrap/app.php kept calling the old name, so every operational error would
 * have fataled at runtime with "Call to undefined method" while the suite stayed
 * green.
 *
 * These are deliberately narrow static assertions over the bootstrap file. They
 * fail loudly when the §66 envelope contract is broken again.
 */
final class ErrorEnvelopeWiringTest extends TestCase
{
    private string $bootstrap = '';

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/bootstrap/app.php';

        if (! is_file($path)) {
            $this->fail('bootstrap/app.php not found at ' . $path);
        }

        $this->bootstrap = (string) file_get_contents($path);
    }

    public function testBootstrapDoesNotUseRfc7807(): void
    {
        $this->assertFalse(
            str_contains($this->bootstrap, 'toProblem'),
            'bootstrap/app.php must call AppError::toResponse(), not the removed toProblem()'
        );

        $this->assertFalse(
            str_contains($this->bootstrap, 'problem+json'),
            'the §66 envelope is served as application/json, never application/problem+json'
        );
    }

    public function testBootstrapRendersTheSection66Envelope(): void
    {
        $this->assertTrue(
            str_contains($this->bootstrap, 'toResponse('),
            'the operational branch must render AppError::toResponse()'
        );

        $this->assertTrue(
            str_contains($this->bootstrap, "'error' =>"),
            'the fallback 500 body must use the nested §66 envelope'
        );

        $this->assertTrue(
            str_contains($this->bootstrap, "'Content-Type' => 'application/json'"),
            'error responses must be served as application/json'
        );
    }

    public function testFallbackErrorBodyHasNoRfc7807Members(): void
    {
        foreach (["'type' =>", "'title' =>", "'detail' =>"] as $member) {
            $this->assertFalse(
                str_contains($this->bootstrap, $member),
                'RFC 7807 member ' . $member . ' must not appear in the error handler'
            );
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
}
