<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * What a signed account token is allowed to do (ТЗ §5).
 *
 * A single token type for three flows would be a real vulnerability, not a
 * simplification: a token mailed to confirm an address would also reset the
 * password of whoever received it. The purpose is therefore part of the signed
 * payload, so a token minted for one flow cannot be replayed into another —
 * `AccountTokenService::verify()` compares it before anything else.
 *
 * The values are stored in the token and compared verbatim, so they are part of
 * the wire format between the mail that carries the link and the endpoint that
 * consumes it. Renaming a case silently invalidates every link already sent, so
 * the strings are stable and are never derived from the enum name.
 */
enum AccountTokenPurpose: string
{
    /** `POST /api/v1/auth/reset-password` may consume it. */
    case PasswordReset = 'password_reset';

    /** `POST /api/v1/auth/verify-email` may consume it. */
    case EmailVerification = 'email_verification';
}
