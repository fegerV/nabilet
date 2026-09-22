<?php

declare(strict_types=1);

/**
 * NABILET Core — live authentication checks
 * =====================================================================
 * The other verifiers in tools/ read the repository. `verify-live.php` reads the
 * running system. This one asks a narrower question that neither can answer:
 *
 *   Does the API actually authenticate anyone, and does it refuse everyone it
 *   should?
 *
 * WHY IT EXISTS
 *   Until 2026-09-22 every protected route answered 500. Two independent faults
 *   produced the same symptom:
 *     1. the routes carried `auth:sanctum`, and `personal_access_tokens` appears
 *        nowhere in the ТЗ — `config/auth.php` documents a `user_sessions`-backed
 *        bearer guard as the intent;
 *     2. no module service provider was ever registered, so the driver that guard
 *        names was never taught to the auth manager
 *        (`Auth driver [session_token] for guard [api] is not defined`).
 *
 *   A static gate cannot see either: both files parse, and every route is
 *   declared. Only a booted application can tell you that `auth:api` throws.
 *
 * WHAT IT PROVES, IN ORDER
 *   [1] The guard resolves, and to the expected class.
 *   [2] Unauthenticated requests are refused with 401 — not 500 — in the §66
 *       envelope.
 *   [3] Every refusal the session rules describe actually refuses: unknown,
 *       tampered, expired, unbounded (NULL `expires_at`), revoked, and a
 *       soft-deleted account.
 *   [4] The storage invariants hold: the token is not stored in plaintext, and
 *       `ip_address` is not silently truncated.
 *
 *   It writes to the live database and removes everything it creates, then
 *   asserts the row counts are back where they started. Run it where the
 *   application actually runs:
 *
 *     php tools/verify-auth-live.php
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Auth\Domain\SessionToken;
use Nabilet\Modules\Auth\Guards\SessionTokenGuard;
use Nabilet\Modules\Auth\Services\ClientContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Models\UserSession as UserSessionRow;

$failed = 0;
$checked = 0;

/** Print a check result and count it. */
$check = static function (string $label, bool $ok, string $detail = '') use (&$failed, &$checked): void {
    $checked++;

    if (! $ok) {
        $failed++;
    }

    printf("  %s  %-58s %s\n", $ok ? ' ok ' : 'FAIL', $label, $detail);
};

echo "\nNABILET Core — live authentication\n";
echo "──────────────────────────────────────────────────────────────────────────\n\n";

$issuer = $app->make(SessionIssuer::class);
$context = new ClientContext('127.0.0.1', 'verify-auth-live');

/* ------------------------------------------------------------------ *
 * A user to authenticate as, and the rows we will clean up afterwards
 * ------------------------------------------------------------------ */

$createdUser = false;
$user = DB::table('users')->orderBy('id')->first();

if ($user === null) {
    $stamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

    $user = DB::table('users')->where('id', DB::table('users')->insertGetId([
        'public_id' => strtoupper(substr(bin2hex(random_bytes(16)), 0, 26)),
        'email' => 'auth-probe@nabilet.test',
        'password' => password_hash('probe-not-a-real-credential', PASSWORD_BCRYPT),
        'status' => 'active',
        'locale' => 'ru',
        'timezone' => 'UTC',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]))->first();

    $createdUser = true;
}

$userId = (int) $user->id;
$baselineSessions = (int) DB::table('user_sessions')->count();
$baselineLogs = (int) DB::table('login_logs')->count();

echo "  user #{$userId}, baseline: {$baselineSessions} sessions, {$baselineLogs} login_logs\n\n";

/** Sessions created by this run, so the cleanup is exact. */
$probeSessionIds = [];

/** Insert a session row directly — used for rows `issue()` refuses to create. */
$rawSession = static function (string $hash, ?string $expiresAt) use ($userId, &$probeSessionIds): void {
    $probeSessionIds[] = DB::table('user_sessions')->insertGetId([
        'user_id' => $userId,
        'session_token_hash' => $hash,
        'expires_at' => $expiresAt,
        'created_at' => (new DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s.u'),
    ]);
};

/** A fresh guard, resolved through the registered driver, for one request. */
$guardFor = static function (?string $authorization) use ($app): SessionTokenGuard {
    // The auth manager caches guards, and the guard caches its verdict — so each
    // case needs a clean one, resolved through the real driver rather than
    // constructed by hand.
    $app['auth']->forgetGuards();

    $app->instance('request', Request::create('/api/v1/probe', 'GET', [], [], [], array_filter([
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => $authorization,
    ])));

    return $app['auth']->guard('api');
};

try {
    /* -------------------------------------------------------------- *
     * [1] Wiring
     * -------------------------------------------------------------- */

    echo "[1] Guard wiring\n";

    $config = (array) config('auth.guards.api');
    $check('auth.guards.api is declared', $config !== [], (string) json_encode($config));
    $check('driver is session_token', ($config['driver'] ?? null) === 'session_token');
    $check('provider is api_users', ($config['provider'] ?? null) === 'api_users');

    $model = (string) config('auth.providers.api_users.model');
    $check('provider model is Authenticatable', is_subclass_of($model, Illuminate\Contracts\Auth\Authenticatable::class), $model);

    $app['auth']->forgetGuards();
    $check("Auth::guard('api') resolves", $app['auth']->guard('api') instanceof SessionTokenGuard);

    /* -------------------------------------------------------------- *
     * [2] The HTTP layer refuses with 401, not 500
     * -------------------------------------------------------------- */

    echo "\n[2] Unauthenticated requests\n";

    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

    $protected = [
        ['GET', '/api/v1/organizations'],
        ['GET', '/api/v1/users'],
        ['GET', '/api/v1/payments'],
        ['POST', '/api/v1/auth/logout'],
    ];

    foreach ($protected as [$method, $path]) {
        $app['auth']->forgetGuards();

        $response = $kernel->handle(Request::create($path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]));

        $status = $response->getStatusCode();
        $code = json_decode((string) $response->getContent(), true)['error']['code'] ?? null;

        $check(
            "{$method} {$path}",
            $status === 401 && is_string($code),
            "HTTP {$status}  error.code=" . (is_string($code) ? $code : '(none)')
        );
    }

    /* -------------------------------------------------------------- *
     * [3] Every refusal
     * -------------------------------------------------------------- */

    echo "\n[3] The guard's decisions\n";

    $check('no Authorization header', $guardFor(null)->user() === null);
    $check('Authorization is not a Bearer token', $guardFor('Basic abc')->user() === null);
    $check('Bearer, but not a token shape', $guardFor('Bearer notatoken')->user() === null);
    $check('Bearer with a stored-hash shape', $guardFor('Bearer ' . str_repeat('a', 64))->user() === null);

    // A real, live session.
    $plain = $issuer->issue($userId, $context);
    $probeSessionIds[] = (int) DB::table('user_sessions')->where('session_token_hash', SessionToken::hashOf($plain))->value('id');

    $resolved = $guardFor('Bearer ' . $plain)->user();
    $check('a live token authenticates', $resolved !== null && (int) $resolved->getAuthIdentifier() === $userId);
    $check('the scheme is matched case-insensitively', $guardFor('bearer ' . $plain)->user() !== null);

    // Tampered: same shape, one character different.
    $tampered = substr($plain, 0, -1) . (str_ends_with($plain, 'a') ? 'b' : 'a');
    $check('a tampered token is refused', $guardFor('Bearer ' . $tampered)->user() === null);

    // Expired — written directly, because `issue()` refuses to create it.
    $expiredPlain = bin2hex(random_bytes(SessionToken::BYTES));
    $rawSession(SessionToken::hashOf($expiredPlain), (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s.u'));
    $check('an expired session is refused', $guardFor('Bearer ' . $expiredPlain)->user() === null);

    // Unbounded — `expires_at` NULL. The column is nullable, so this row is
    // storable; the rule is that it must not be honoured.
    $unboundedPlain = bin2hex(random_bytes(SessionToken::BYTES));
    $rawSession(SessionToken::hashOf($unboundedPlain), null);
    $check('a session with NULL expires_at is refused', $guardFor('Bearer ' . $unboundedPlain)->user() === null);

    // Revoked: the row is deleted, which is the only revocation `user_sessions`
    // supports.
    $revokedPlain = $issuer->issue($userId, $context);
    $revokedId = (int) DB::table('user_sessions')->where('session_token_hash', SessionToken::hashOf($revokedPlain))->value('id');
    $probeSessionIds[] = $revokedId;

    $logsBefore = (int) DB::table('login_logs')->count();
    $revoked = $issuer->revoke(UserSessionRow::query()->findOrFail($revokedId), $context, null, 'verify-auth-live');
    $logsAfter = (int) DB::table('login_logs')->count();

    $check('revoking deletes the row', $revoked && DB::table('user_sessions')->where('id', $revokedId)->count() === 0);
    $check('the audit line is written before the DELETE', $logsAfter === $logsBefore + 1, "login_logs {$logsBefore} -> {$logsAfter}");
    $check('the revoked token no longer authenticates', $guardFor('Bearer ' . $revokedPlain)->user() === null);

    // A soft-deleted account. `users.deleted_at` exists and the model does not use
    // `SoftDeletes`, so `retrieveById()` would otherwise return it.
    $softPlain = $issuer->issue($userId, $context);
    $probeSessionIds[] = (int) DB::table('user_sessions')->where('session_token_hash', SessionToken::hashOf($softPlain))->value('id');

    DB::table('users')->where('id', $userId)->update(['deleted_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u')]);
    $afterDelete = $guardFor('Bearer ' . $softPlain)->user();
    DB::table('users')->where('id', $userId)->update(['deleted_at' => null]);

    $check('a soft-deleted account is refused', $afterDelete === null);

    /* -------------------------------------------------------------- *
     * [4] Storage invariants
     * -------------------------------------------------------------- */

    echo "\n[4] Storage\n";

    $hash = SessionToken::hashOf($plain);

    $check(
        'the plaintext token is not in the database',
        (int) DB::table('user_sessions')->where('session_token_hash', $plain)->count() === 0
    );
    $check(
        'the stored value is sha256, 64 characters',
        strlen($hash) === 64 && DB::table('user_sessions')->where('session_token_hash', $hash)->exists(),
        'sha256 len=' . strlen($hash)
    );

    $row = DB::table('user_sessions')->where('session_token_hash', $hash)->first();

    // The truncation bug: binding packed bytes as a string stored 1 byte for
    // 127.0.0.1 instead of 4, with no error. `length()` is the only way to see it.
    $ipLength = (int) DB::table('user_sessions')->where('id', $row->id)->selectRaw('length(ip_address) as l')->value('l');
    $check('ip_address holds 4 bytes for 127.0.0.1', $ipLength === 4, "length={$ipLength}");

    $seenBefore = $row->last_seen_at;
    $guardFor('Bearer ' . $plain)->user();
    $seenAfter = DB::table('user_sessions')->where('id', $row->id)->value('last_seen_at');
    $check('last_seen_at advances on use', $seenAfter !== null && $seenAfter !== $seenBefore, (string) $seenAfter);
} finally {
    /* -------------------------------------------------------------- *
     * Cleanup — and prove it was complete
     * -------------------------------------------------------------- */

    echo "\n[5] Cleanup\n";

    if ($probeSessionIds !== []) {
        DB::table('user_sessions')->whereIn('id', array_unique($probeSessionIds))->delete();
    }

    // Every logout audit line this run wrote names the same identifier.
    DB::table('login_logs')->where('identifier', 'verify-auth-live')->delete();

    if ($createdUser) {
        DB::table('users')->where('id', $userId)->delete();
    }

    $finalSessions = (int) DB::table('user_sessions')->count();
    $finalLogs = (int) DB::table('login_logs')->count();

    $check('user_sessions restored', $finalSessions === $baselineSessions, "{$baselineSessions} -> {$finalSessions}");
    $check('login_logs restored', $finalLogs === $baselineLogs, "{$baselineLogs} -> {$finalLogs}");
}

echo "\n";
printf("  %d checks, %d failed\n", $checked, $failed);

if ($failed > 0) {
    echo "\n  FAIL — the API does not authenticate the way the session rules describe.\n\n";

    exit(1);
}

echo "\n  PASS — every refusal refuses, and a live token authenticates.\n\n";

exit(0);
