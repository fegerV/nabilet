<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

/**
 * What an erasure request actually has to do.
 *
 * THE POINT OF THIS CLASS IS THAT ERASURE IS NOT DELETE.
 *
 * A person asks to be forgotten, and the honest answer is "mostly". An order
 * that has been paid for is an accounting record: tax and bookkeeping rules
 * require it to be kept for years after the customer has gone. Deleting the row
 * to satisfy the request would break a different obligation, and doing it
 * silently is worse than either.
 *
 * So the plan splits the work:
 *
 *   - personal data (name, email, phone, holder names on tickets, consents) is
 *     deleted;
 *   - the financial record is RETAINED but DETACHED from the person — the order
 *     survives, the customer's identity does not.
 *
 * Keeping the money and losing the name is the only shape that satisfies both
 * obligations, and it has to be stated explicitly rather than left to whoever
 * writes the DELETE statement.
 */
final class ErasurePlan
{
    public function __construct(
        public readonly bool $deletePersonalData,
        public readonly bool $detachHolderNames,
        public readonly int $ordersToAnonymize,
        public readonly bool $retainFinancialRecords,
        public readonly string $reason,
    ) {
    }

    public static function forSubject(int $ordersWithFinancialRecords): self
    {
        return new self(
            deletePersonalData: true,
            detachHolderNames: true,
            ordersToAnonymize: $ordersWithFinancialRecords,
            retainFinancialRecords: true,
            reason: $ordersWithFinancialRecords > 0
                ? sprintf(
                    'delete personal data; retain %d order(s) as accounting records with the '
                    . 'person detached. Erasure is anonymisation here, not deletion.',
                    $ordersWithFinancialRecords
                )
                : 'delete personal data; there are no financial records to retain.',
        );
    }

    /** True when the request can be satisfied by straight deletion. */
    public function isPlainDeletion(): bool
    {
        return $this->ordersToAnonymize === 0;
    }
}
