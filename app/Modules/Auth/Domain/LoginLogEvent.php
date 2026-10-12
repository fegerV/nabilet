<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * The three events `login_logs` can record, and nothing else.
 *
 * WHY THIS EXISTS
 *   The table was written from exactly one place — `SessionIssuer::auditLogout()`
 *   — so every row in it was a logout. A login could only be told apart by the
 *   presence of `failure_code`, and that inference broke the moment a writer
 *   logged a *successful* login, which the audit trail is supposed to contain.
 *   The column (split `016_login_logs_event.sql`) gives the events storage;
 *   this class is the single place the vocabulary lives, for the same reason
 *   `UserStatus` exists: a list written as a string in each caller is a list
 *   that drifts.
 *
 * THE VALUES ARE NOT INVENTED
 *   These are the three events the domain already distinguishes — a session is
 *   issued (`SessionIssuer::issue`), ended (`::revoke`), or refused
 *   (`AuthError('bad_credentials')`). Nothing here names an event the
 *   application cannot produce.
 */
final class LoginLogEvent
{
    public const LOGIN = 'login';

    public const LOGOUT = 'logout';

    public const LOGIN_FAILED = 'login_failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::LOGIN, self::LOGOUT, self::LOGIN_FAILED];
    }
}
