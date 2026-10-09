<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Domain;

/**
 * The `api_keys.scopes_json` entry that makes a key an embed token.
 *
 * ТЗ §18 lists what the backend must check for an embed request: Origin, embed
 * token, organization ownership, event access, payment capability. The token half
 * of that is a scope, not a separate table — `api_keys` already carries
 * `organization_id`, `expires_at`, `revoked_at` and `scopes_json`, and
 * `ApiKeyPolicy` already refuses a key that is revoked, unbounded, expired or
 * scopeless. A second credential table would have to re-earn all four of those
 * decisions, and would probably earn three of them.
 *
 * Kept as a class rather than a bare string so that the one place that reads the
 * scope and the one place that would grant it cannot disagree by typo.
 */
final class EmbedScope
{
    public const EMBED = 'embed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EMBED];
    }
}
