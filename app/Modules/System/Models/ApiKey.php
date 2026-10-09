<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Security\Domain\ApiKey as DomainApiKey;

/**
 * One row of `api_keys` — the credential an embed widget authenticates with.
 *
 * WHAT WAS WRONG HERE
 *   This model declared `token_hash`, `abilities` and `last_used_at`. None of the
 *   three is a column. The table stores `public_id`, `key_prefix`, `key_hash`,
 *   `scopes_json`, `expires_at` and `revoked_at`, and a count of columns able to
 *   record last use returned **0** (REVIEW §3.19).
 *
 *   The names were not merely unused: `Organization::apiKeys()` pointed at this
 *   class, so the relation would have selected columns MySQL does not have. The
 *   three invented fields were recorded as known debt in
 *   `tools/verify-models-schema.php`; they are removed from that ratchet in the
 *   same change that fixes the model, because a ratchet that keeps a fixed entry
 *   stops being a ratchet.
 *
 * WHY `toDomain()` EXISTS
 *   The rules that decide whether a key may be used — revoked, unbounded, expired,
 *   carrying no scopes — are pure, and they are already written, in
 *   `Nabilet\Modules\Security\Domain\ApiKeyPolicy`. Deriving them a second time
 *   from Eloquent attributes would be a second copy of the same policy, and the
 *   copies would drift. The model's only job is to hand the row over.
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    /**
     * `api_keys` has `created_at` and no `updated_at` column.
     *
     * `public $timestamps = false` would be the wrong tool: it switches `created_at`
     * off as well, and that column is NOT NULL with no default, so every insert
     * would fail. Suppressing only the update side is what the schema says.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'key_prefix',
        'key_hash',
        'scopes_json',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes_json' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * The digest is the credential itself. Hiding it here means a stray
     * `$key->toArray()` — in a resource, a log context, an error payload — cannot
     * publish it, which is the failure mode that turns a leak into a break-in.
     */
    protected $hidden = ['key_hash'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The pure decision layer's view of this row. */
    public function toDomain(): DomainApiKey
    {
        return new DomainApiKey(
            id: (int) $this->id,
            publicId: (string) $this->public_id,
            organizationId: $this->organization_id === null ? null : (int) $this->organization_id,
            name: (string) $this->name,
            keyPrefix: (string) $this->key_prefix,
            keyHash: (string) $this->key_hash,
            createdAt: \Carbon\CarbonImmutable::instance($this->created_at ?? now()),
            scopes: $this->scopes_json,
            expiresAt: $this->expires_at === null ? null : \Carbon\CarbonImmutable::instance($this->expires_at),
            revokedAt: $this->revoked_at === null ? null : \Carbon\CarbonImmutable::instance($this->revoked_at),
        );
    }
}
