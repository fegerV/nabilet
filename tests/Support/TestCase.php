<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

/**
 * Minimal test case base class.
 *
 * Deliberately tiny: PHPUnit cannot be installed in this sandbox (no Composer
 * network access), but the kernel's correctness must still be provable. The API
 * mirrors PHPUnit closely enough that migrating these tests to PHPUnit later is a
 * mechanical change.
 */
abstract class TestCase
{
    /** Assertions performed by the test method currently running. */
    public int $assertions = 0;

    /** Assertions performed across the whole class. */
    public int $totalAssertions = 0;

    /** @var list<string> */
    public array $failures = [];

    private string $currentTest = '';

    /**
     * @return array{passed: int, failed: int, assertions: int}
     */
    final public function run(): array
    {
        $methods = array_filter(
            get_class_methods($this),
            static fn (string $m): bool => str_starts_with($m, 'test')
        );

        $passed = 0;
        $failed = 0;

        foreach ($methods as $method) {
            $this->currentTest = $method;
            $this->assertions = 0;
            $before = count($this->failures);

            try {
                $this->setUp();
                $this->{$method}();
            } catch (AssertionFailed $e) {
                // already recorded
            } catch (\Throwable $e) {
                $this->failures[] = sprintf(
                    '%s::%s threw %s: %s',
                    static::class,
                    $method,
                    $e::class,
                    $e->getMessage()
                );
            }

            $this->totalAssertions += $this->assertions;

            if (count($this->failures) === $before) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return ['passed' => $passed, 'failed' => $failed, 'assertions' => $this->totalAssertions];
    }

    protected function setUp(): void
    {
    }

    // ------------------------------------------------------------- assertions

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== true) {
            $this->fail(sprintf('%sExpected true, got %s.', $this->prefix($message), $this->describe($actual)));
        }
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== false) {
            $this->fail(sprintf('%sExpected false, got %s.', $this->prefix($message), $this->describe($actual)));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(sprintf(
                '%sExpected %s, got %s.',
                $this->prefix($message),
                $this->describe($expected),
                $this->describe($actual)
            ));
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            $this->fail(sprintf(
                '%sExpected a value different from %s.',
                $this->prefix($message),
                $this->describe($actual)
            ));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual === null) {
            $this->fail($this->prefix($message) . 'Expected a non-null value.');
        }
    }

    /** @param array<mixed> $haystack */
    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (! in_array($needle, $haystack, true)) {
            $this->fail(sprintf(
                '%sExpected %s to be present in [%s].',
                $this->prefix($message),
                $this->describe($needle),
                implode(', ', array_map($this->describe(...), $haystack))
            ));
        }
    }

    /** @param array<mixed> $haystack */
    protected function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (in_array($needle, $haystack, true)) {
            $this->fail(sprintf('%sDid not expect %s to be present.', $this->prefix($message), $this->describe($needle)));
        }
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message);
    }

    /**
     * Assert that $callback throws an instance of $expectedClass.
     */
    protected function assertThrows(string $expectedClass, callable $callback, string $message = ''): void
    {
        $this->assertions++;

        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $expectedClass) {
                return;
            }

            $this->fail(sprintf(
                '%sExpected %s, got %s (%s).',
                $this->prefix($message),
                $expectedClass,
                $e::class,
                $e->getMessage()
            ));

            return;
        }

        $this->fail(sprintf('%sExpected %s to be thrown, but nothing was thrown.', $this->prefix($message), $expectedClass));
    }

    protected function fail(string $message): never
    {
        $this->failures[] = sprintf('%s::%s — %s', static::class, $this->currentTest, $message);

        throw new AssertionFailed($message);
    }

    private function prefix(string $message): string
    {
        return $message === '' ? '' : $message . ' ';
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) => '"' . $value . '"',
            is_array($value) => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default => (string) $value,
        };
    }
}

/** Internal control-flow signal; never escapes the runner. */
final class AssertionFailed extends \Exception
{
}
