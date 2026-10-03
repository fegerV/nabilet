<?php

declare(strict_types=1);

/**
 * NABILET Core — repro: `role:admin` is an alias nothing registers
 * =====================================================================
 * CLAIM (docs/SERVER-HEALTH.md §9, docs/REVIEW-spec-bundle.md §3.25.3):
 *
 *   Every Halls management route is unreachable. It answers 401 to an anonymous
 *   caller and **500** to a correctly authenticated one, because the route group
 *   is guarded by `Route::middleware(['auth:api', 'role:admin'])` while
 *   `role` is not a registered middleware alias and no `RequireRole` class
 *   exists to alias in the first place.
 *
 * WHY A STATIC GATE CANNOT SEE IT
 *   `bootstrap/app.php` aliases only kernel middleware and says, in a comment,
 *   that modules register their own aliases from their service providers.
 *   `routes/api.php` still mounts every module route file, so the route is
 *   declared and the file parses. Nothing is missing from the repository's point
 *   of view — only a booted application that resolves the middleware stack
 *   discovers that `role` cannot be constructed.
 *
 * WHY THE ANONYMOUS CASE IS NOT ENOUGH
 *   `auth:api` runs first, so an anonymous request is refused before `role:admin`
 *   is ever resolved. Probing only the anonymous case reports "401, working".
 *   The failure is visible only to an authenticated caller — the same shape as
 *   the `Container::refresh()` trap in §3.25.2.
 *
 * RUN IT WHERE THE APPLICATION RUNS:
 *
 *     php tools/repro-role-alias.php
 *
 * EXIT CODE
 *   0 — the documented defect is still present, exactly as described.
 *   1 — the behaviour CHANGED. Either the alias was implemented (then update
 *       §9 / §3.25.3 and this script) or something else broke. Do not leave
 *       this script red without recording what changed.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Auth\Services\ClientContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Models\UserSession as UserSessionRow;

$kernel = $app->make(Kernel::class);

echo "\nNABILET Core — repro: the `role:admin` alias\n";
echo str_repeat('─', 74) . "\n\n";

// ── 1. What the router actually knows ────────────────────────────────────────
$aliases = array_keys($app['router']->getMiddleware());

echo "[1] Middleware aliases the router knows\n";
printf("    %s\n\n", implode(', ', $aliases));

$hasRoleAlias = in_array('role', $aliases, true);
$hasRoleClass = class_exists(\Nabilet\Core\Http\Middleware\RequireRole::class);

printf("    'role' alias registered?          %s\n", $hasRoleAlias ? 'YES' : 'NO');
printf("    RequireRole class exists?         %s\n\n", $hasRoleClass ? 'YES' : 'NO');

// ── 2. Ask the route itself what it is guarded by ────────────────────────────
$route = null;

foreach ($app['router']->getRoutes() as $candidate) {
    if ($candidate->uri() === 'api/v1/halls' && in_array('POST', $candidate->methods(), true)) {
        $route = $candidate;
        break;
    }
}

echo "[2] The route under test\n";

if ($route === null) {
    echo "    POST /api/v1/halls is not registered at all — this repro cannot run.\n";
    echo "    Update it: the Halls routes moved or were renamed.\n\n";
    exit(1);
}

printf("    %s  %s\n", implode('|', $route->methods()), $route->uri());
printf("    middleware: %s\n\n", implode(', ', $route->gatherMiddleware()));

// ── 3. A real token for a real user ──────────────────────────────────────────
$userId = (int) DB::table('users')->orderBy('id')->value('id');

if ($userId === 0) {
    echo "    No rows in `users` — cannot issue a session. Seed a user first.\n\n";
    exit(1);
}

$issuer = $app->make(SessionIssuer::class);
$token = $issuer->issue($userId, ClientContext::fromRequest(Request::create('/')));

echo "[3] A live session for user #{$userId} was issued\n\n";

/** Dispatch one request with a fresh guard, as a real request would. */
$send = static function (string $method, string $uri, ?string $token) use ($kernel, $app): array {
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $request = Request::create($uri, $method, [], [], [], $server, $method === 'POST' ? '{}' : null);

    // The guard memoises its verdict — including a negative one — and the auth
    // manager memoises the guard. Without this reset the first anonymous refusal
    // is replayed for every later request in the same process, which manufactures
    // a "the token does not work either" conclusion. `verify-auth-live.php` resets
    // the same way, for the same reason.
    $app->instance('request', $request);
    $app['auth']->forgetGuards();

    $response = $kernel->handle($request);

    return [$response->getStatusCode(), (string) $response->getContent()];
};

// ── 4. Both cases ────────────────────────────────────────────────────────────
[$anonStatus, $anonBody] = $send('POST', '/api/v1/halls', null);
[$authStatus, $authBody] = $send('POST', '/api/v1/halls', $token);

$code = static function (string $json): string {
    $decoded = json_decode($json, true);

    return is_array($decoded) ? (string) ($decoded['error']['code'] ?? '?') : '?';
};

echo "[4] The two cases\n";
printf("    anonymous        -> %d  %-20s %s\n", $anonStatus, $code($anonBody), 'auth:api refuses first');
printf("    authenticated    -> %d  %-20s %s\n\n", $authStatus, $code($authBody), 'the alias cannot be resolved');

// ── 5. Clean up before judging, so a failure cannot leak rows ────────────────
$deleted = UserSessionRow::query()->where('user_id', $userId)->delete();

printf("[5] Cleanup: %d session row(s) removed; user_sessions now %d\n\n", $deleted, UserSessionRow::query()->count());

// ── 6. Verdict ───────────────────────────────────────────────────────────────
$stillBroken = $authStatus === 500 && ! $hasRoleAlias && ! $hasRoleClass;

if ($stillBroken) {
    echo "  REPRO OK — the defect is present exactly as documented:\n";
    echo "    an authenticated caller cannot reach POST /api/v1/halls; it 500s on\n";
    echo "    `Target class [role] does not exist`. See §9 / §3.25.3 for the two\n";
    echo "    candidate remedies, neither of which is applied yet.\n\n";

    exit(0);
}

echo "  BEHAVIOUR CHANGED — this repro no longer matches the documentation.\n\n";

if ($hasRoleAlias || $hasRoleClass) {
    echo "    The `role` middleware now resolves. That is the fix this script was\n";
    echo "    written to detect: update docs/SERVER-HEALTH.md §9, docs/REVIEW-spec-bundle.md\n";
    echo "    §3.25.3, and rewrite this script to assert the new contract.\n\n";
} else {
    printf("    `role` is still unregistered, but the authenticated call answered %d, not 500.\n", $authStatus);
    echo "    Something else changed the middleware stack. Investigate before updating the docs.\n\n";
}

exit(1);
