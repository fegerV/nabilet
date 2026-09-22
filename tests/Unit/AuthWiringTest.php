<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * The API's authentication wiring, asserted against the files that declare it.
 *
 * These are not style checks. Every one of them is a fault that produced a live
 * 500 on 2026-09-22 and that nothing else in the suite could see:
 *
 *   - the routes carried `auth:sanctum`, and `personal_access_tokens` appears
 *     nowhere in the ТЗ — `config/auth.php` documents a `user_sessions`-backed
 *     bearer guard as the intent. Every protected route answered 500.
 *   - no module service provider was ever registered, so the driver the `api`
 *     guard names was never taught to the auth manager, and `auth:api` threw
 *     `Auth driver [session_token] for guard [api] is not defined`.
 *   - `Container::refresh()` does not call the method it is given: it registers a
 *     rebinding callback. A driver that calls only `refresh()` leaves the guard
 *     with a null request, so it refuses **every** token — and the refusal tests
 *     still pass, because "no token" and "bad token" are indistinguishable from
 *     outside. That one was found by `tools/verify-auth-live.php`, not here.
 *
 * A green result means the wiring is described correctly in the files. It does not
 * mean a request authenticates; only the live verifier can say that.
 */
final class AuthWiringTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    // ── the guard is declared, and backed by the right table ────────────────

    public function testConfigDeclaresTheApiGuardOnTheSessionTokenDriver(): void
    {
        $config = $this->read('config/auth.php');

        $this->assertStringContainsString("'api' =>", $config);
        $this->assertStringContainsString("'driver' => 'session_token'", $config);
        $this->assertStringContainsString("'provider' => 'api_users'", $config);
    }

    public function testTheApiProviderPointsAtTheModuleUser(): void
    {
        // `App\Models\User` is the Filament panel's model. The modules use the one
        // below, and a guard that returned the other would hand controllers a
        // different class for the same row.
        $this->assertStringContainsString(
            'Nabilet\Modules\Core\Users\Models\User::class',
            $this->read('config/auth.php')
        );
    }

    public function testTheModuleUserIsAuthenticatable(): void
    {
        // `EloquentUserProvider` refuses a plain `Model`, so the provider above
        // cannot work unless this holds.
        $this->assertStringContainsString(
            'use Illuminate\Foundation\Auth\User as Authenticatable;',
            $this->read('app/Modules/Core/Users/Models/User.php')
        );
        $this->assertStringContainsString(
            'class User extends Authenticatable',
            $this->read('app/Modules/Core/Users/Models/User.php')
        );
    }

    // ── no route asks for a mechanism the ТЗ does not define ────────────────

    public function testNoRouteAsksForSanctum(): void
    {
        $routeFiles = [
            'routes/api.php',
            'app/Modules/Auth/routes/api.php',
            'app/Modules/Core/Organizations/routes/api.php',
            'app/Modules/Core/Users/routes/api.php',
            'app/Modules/Payments/routes/api.php',
            'app/Modules/Venues/Halls/routes/api.php',
        ];

        foreach ($routeFiles as $file) {
            $this->assertFalse(
                str_contains($this->read($file), 'auth:sanctum'),
                "{$file} still asks for auth:sanctum"
            );
        }
    }

    public function testTheProtectedRoutesAskForTheApiGuard(): void
    {
        $this->assertStringContainsString('auth:api', $this->read('app/Modules/Auth/routes/api.php'));
        $this->assertStringContainsString('auth:api', $this->read('app/Modules/Core/Users/routes/api.php'));
        $this->assertStringContainsString('auth:api', $this->read('app/Modules/Core/Organizations/routes/api.php'));
        $this->assertStringContainsString('auth:api', $this->read('app/Modules/Payments/routes/api.php'));
        $this->assertStringContainsString('auth:api', $this->read('app/Modules/Venues/Halls/routes/api.php'));
    }

    // ── the driver is registered, and by a provider that actually boots ─────

    public function testTheAuthProviderIsRegisteredWithTheApplication(): void
    {
        // Nothing referenced the module registry, so no module provider booted and
        // the driver was never registered. This is what makes it boot.
        $this->assertStringContainsString(
            'Nabilet\Modules\Auth\Providers\AuthServiceProvider::class',
            $this->read('bootstrap/providers.php')
        );
    }

    public function testTheProviderRegistersTheSessionTokenDriver(): void
    {
        $provider = $this->read('app/Modules/Auth/Providers/AuthServiceProvider.php');

        $this->assertStringContainsString('Auth::extend(', $provider);
        $this->assertStringContainsString("const DRIVER = 'session_token'", $provider);
    }

    public function testTheGuardGetsTheRequestImmediatelyAndNotOnlyViaRefresh(): void
    {
        // `Container::refresh()` only *registers a rebinding callback* — it does not
        // call the method. Written on its own it reads correctly and silently leaves
        // the guard with a null request, so every token is refused and the refusal
        // tests still pass. Both calls must be present.
        $provider = $this->read('app/Modules/Auth/Providers/AuthServiceProvider.php');

        $this->assertStringContainsString('$guard->setRequest(', $provider);
        $this->assertStringContainsString("$app->refresh('request'", $provider);
    }

    public function testTheProviderDoesNotLoadRoutesItself(): void
    {
        // `ServiceProvider::loadRoutesFrom()` is a bare `require` with no group and
        // no prefix. These routes belong under `/api/v1` and are required from
        // `routes/api.php` inside that group, so loading them here as well would
        // register every auth endpoint a second time at `/auth/*` — unversioned and
        // outside the API middleware group.
        //
        // The call form is asserted, not the bare name: the provider's own comment
        // explains why it does not load routes, and that comment contains the word.
        $this->assertFalse(
            str_contains(
                $this->read('app/Modules/Auth/Providers/AuthServiceProvider.php'),
                '$this->loadRoutesFrom('
            )
        );
    }

    // ── the guard itself ────────────────────────────────────────────────────

    public function testTheGuardImplementsTheFrameworkContract(): void
    {
        $guard = $this->read('app/Modules/Auth/Guards/SessionTokenGuard.php');

        $this->assertStringContainsString('implements Guard', $guard);
        $this->assertStringContainsString('use Illuminate\Contracts\Auth\Guard;', $guard);
    }

    public function testTheGuardDefinesTheTwoMethodsGuardHelpersDoesNot(): void
    {
        // `GuardHelpers` supplies check/guest/id/authenticate/setUser, but NOT
        // `user()` or `validate()`. A guard that assumes otherwise is abstract-free
        // and fatally incomplete at the first request.
        $guard = $this->read('app/Modules/Auth/Guards/SessionTokenGuard.php');

        $this->assertStringContainsString('use GuardHelpers;', $guard);
        $this->assertStringContainsString('public function user()', $guard);
        $this->assertStringContainsString('public function validate(', $guard);
    }

    public function testTheGuardReadsABearerTokenCaseInsensitively(): void
    {
        $guard = $this->read('app/Modules/Auth/Guards/SessionTokenGuard.php');

        $this->assertStringContainsString("header('Authorization')", $guard);
        // RFC 7235: the scheme is case-insensitive, so `bearer` is not a client bug.
        $this->assertStringContainsString('/i', $guard);
    }

    public function testTheTokenIsStoredAsAHashAndNeverInPlaintext(): void
    {
        $issuer = $this->read('app/Modules/Auth/Services/SessionIssuer.php');

        $this->assertStringContainsString('SessionToken::generate()', $issuer);
        $this->assertStringContainsString('$token->hash', $issuer);
        $this->assertFalse(
            str_contains($issuer, 'session_token_hash\' => $token->plain'),
            'the plaintext token must never be written to session_token_hash'
        );
    }
}
