<?php

declare(strict_types=1);

namespace App\Modules\HallSchemas\Domain;

/**
 * The answer to "may this version be published / archived / edited?".
 *
 * A result, because nearly every refusal is a normal thing an editor does: trying
 * to save onto a published map is the single most common mistake in a seat-map
 * editor, and it deserves a sentence the user can act on ("make a new version"),
 * not a stack trace.
 */
final class VersionDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /** A sentence for the editor UI. */
    public function message(): string
    {
        return match ($this->reason) {
            null => 'Allowed.',
            SchemaVersionPolicy::REASON_FROZEN =>
                'This version is published and cannot be edited. Duplicate it to a new version instead.',
            SchemaVersionPolicy::REASON_NOT_DRAFT =>
                'Only a draft can be published.',
            SchemaVersionPolicy::REASON_EMPTY_PAYLOAD =>
                'There is no seat map to publish yet.',
            SchemaVersionPolicy::REASON_ANOTHER_VERSION_PUBLISHED =>
                'This hall already has a published version. Archive it first.',
            SchemaVersionPolicy::REASON_NOT_PUBLISHED =>
                'Only a published version can be archived.',
            default => 'Not allowed.',
        };
    }
}
