<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Providers;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Auth\Guards\SessionTokenGuard;
use Nabilet\Modules\Auth\Services\SessionIssuer;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The auth driver name, referenced by the `api` guard in `config/auth.php`.
     *
     * The driver is registered here rather than in config because it is a closure
     * over module code: config can name a driver, it cannot implement one.
     */
    public const DRIVER = 'session_token';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind authentication repositories and services if needed
        // $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This provider deliberately does NOT call `loadRoutesFrom()`.
        //
        // `ServiceProvider::loadRoutesFrom()` is a bare `require` — no group, no
        // prefix. The module's routes belong under `/api/v1`, and they are already
        // required from `routes/api.php` *inside* the `api/v1` group that
        // `bootstrap/app.php` declares. Loading them here as well would register
        // the same endpoints a second time at `/auth/*`, unversioned and outside
        // the API middleware group — a duplicate public surface, not a harmless
        // repeat. Routes stay in one place.
        $this->registerSessionTokenDriver();
    }

    /**
     * Teach the auth manager the `session_token` driver.
     *
     * `config/auth.php` declares the guard — `'api' => ['driver' => 'session_token',
     * 'provider' => 'api_users']` — and this supplies the driver it names, which is
     * the split that file's own header comment describes. Nothing here resolves a
     * guard: the closure runs the first time something asks for `auth('api')`, so
     * an application that never touches the API never builds one.
     */
    private function registerSessionTokenDriver(): void
    {
        Auth::extend(self::DRIVER, function ($app, string $name, array $config): Guard {
            $guard = new SessionTokenGuard(
                Auth::createUserProvider($config['provider'] ?? null),
                $app->make(SessionIssuer::class),
            );

            // BOTH lines below are required. The second one on its own is a silent
            // no-op, and that is not obvious from how it reads.
            //
            // `Container::refresh()` does not call the method: it *registers a
            // rebinding callback* that fires the next time `request` is rebound. So
            // a guard that only calls `refresh()` keeps a null request,
            // `bearerToken()` returns null for every caller, and the guard refuses
            // every token — valid ones included. Worse, the refusal cases still
            // pass, because "no token" and "bad token" look identical from outside.
            // `tools/verify-auth-live.php` is what surfaced it.
            //
            // So the current request is pushed in immediately, and the rebinding is
            // kept for long-running workers, where the guard is cached across
            // requests and the binding is replaced underneath it.
            if ($app->bound('request')) {
                $guard->setRequest($app->make('request'));
            }

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
