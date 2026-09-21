<?php

declare(strict_types=1);

namespace App\Modules\Users\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * How a contact value is compared — which is the database's decision, not PHP's.
 *
 * `users.email` is `VARCHAR(255)` in `utf8mb4_unicode_ci`, so MySQL treats
 * `A@EXAMPLE.COM` and `a@example.com` as the SAME value, and (because the collation
 * is PAD SPACE) `a@example.com ` as the same value too. Both were rejected with
 * `ERROR 1062` on MySQL 8.4 — see `tools/repro-user-identity.sql`.
 *
 * A registration form that compares addresses with `===` in PHP therefore answers
 * "this address is free" and then dies on a duplicate key. Normalising here is what
 * makes the pre-check agree with the column.
 *
 * WHY THE PHONE IS ONLY TRIMMED
 *   Stripping spaces and dashes from a phone number would make PHP STRICTER than
 *   the database: `+7 900 000 00 00` and `+79000000000` are two different values to
 *   a `utf8mb4_unicode_ci` column, because PAD SPACE ignores trailing whitespace
 *   only, not separators inside the string. A uniqueness pre-check that treated them
 *   as one person would refuse a registration the database is willing to store.
 *   Matching the collation exactly — trim, nothing more — is the honest answer, and
 *   the asymmetry between the two channels is recorded rather than smoothed over.
 */
final class ContactChannel
{
    public const EMAIL = 'email';
    public const PHONE = 'phone';

    public static function isValid(string $channel): bool
    {
        return $channel === self::EMAIL || $channel === self::PHONE;
    }

    /**
     * Reduce a contact value to the form the column compares.
     */
    public static function normalise(string $channel, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return match ($channel) {
            self::EMAIL => $trimmed === '' ? null : mb_strtolower($trimmed, 'UTF-8'),
            self::PHONE => $trimmed === '' ? null : $trimmed,
            default => throw new DomainRuleViolation(
                sprintf('Unknown contact channel "%s".', $channel),
                'INVALID_CONTACT_CHANNEL'
            ),
        };
    }

    /** Would the column treat these two as the same row? */
    public static function sameIdentity(string $channel, ?string $left, ?string $right): bool
    {
        return self::normalise($channel, $left) === self::normalise($channel, $right);
    }
}
