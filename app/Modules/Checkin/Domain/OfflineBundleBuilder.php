<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Builds the offline bundle an Android Checker takes with it into a venue with
 * no network (ТЗ §43/§44, §33).
 *
 * PURE: no database, no clock, no I/O. It turns a request into a plan, or says
 * why not. Writing the row is the caller's job.
 *
 * WHY THE HASH IS DEVICE-SCOPED — proved by execution against MySQL 8.4, not
 * reasoned about:
 *
 *   `offline_bundles` carries `UNIQUE KEY uq_offline_bundles_hash (bundle_hash)`
 *   with no device qualifier. Two devices covering the same session receive
 *   IDENTICAL content, therefore an identical content hash, therefore the second
 *   INSERT fails with
 *       ERROR 1062 (23000) Duplicate entry ... for key 'uq_offline_bundles_hash'
 *   Gate A gets a bundle and Gate B is told no. Folding the device public id into
 *   the hashed structure fixes it with no DDL change, and keeps the useful half of
 *   the constraint: the same device asking twice still hashes the same, so
 *   generation can reuse the existing row instead of colliding.
 *
 *   So `generatedAt` is deliberately NOT part of the hashed structure. Including
 *   it would give every regeneration a fresh hash, which turns the unique key
 *   from a deduplication aid into a landmine: "sync again" would fail.
 *
 * THE EXPIRY IS A CEILING ON HOW LONG A STALE LIST MAY BE OBEYED.
 * Offline, the device decides — the server only reconciles afterwards. That makes
 * the bundle an admission authority, and an authority needs an end date. Two
 * bounds, and the earlier of the two wins:
 *
 *   session end + grace  — must not outlive the session it covers. A bundle still
 *                          honoured the day after would admit against a list from
 *                          yesterday.
 *   generatedAt + maxAge — must not go stale before the session. A bundle built
 *                          three days early carries a revoked list that predates
 *                          three days of refunds, and the device would admit every
 *                          one of them.
 *
 * Neither number is fixed by the ТЗ; both are constants here so that they are
 * visible and changeable in one place rather than smeared across callers.
 */
final class OfflineBundleBuilder
{
    /** How long after the session ends a bundle may still be honoured. */
    public const SESSION_GRACE_MINUTES = 120;

    /** Ceiling on a bundle's life when there is no session to derive one from. */
    public const DEFAULT_VALIDITY_HOURS = 24;

    /** Ceiling on a bundle's life in every case. Forces a re-sync before a long event. */
    public const MAX_VALIDITY_HOURS = 24;

    public function decide(OfflineBundleRequest $request): BundleDecision
    {
        // Order matters: report the thing the operator can act on first. A
        // disabled device is a switch someone can flip; a wrong-session ticket is
        // a bug someone has to fix, and only after the obvious is ruled out.
        if (! $request->device->isActive()) {
            return BundleDecision::denied(
                BundleDecision::DEVICE_NOT_ACTIVE,
                sprintf('device "%s" is %s and may not receive a bundle.', $request->device->publicId, $request->device->status)
            );
        }

        // §43: the bundle carries the public key. Without it the device cannot
        // verify a QR signature offline and admission degrades to "is this number
        // on my list?" — and ticket numbers are printed on the tickets.
        if ($request->publicKeyFingerprint === '') {
            return BundleDecision::denied(
                BundleDecision::MISSING_PUBLIC_KEY,
                'a bundle carries the public key fingerprint; without it the device '
                . 'cannot verify signatures offline and can only look numbers up in a list.'
            );
        }

        // Refusing an empty bundle is the point, not a tidiness rule. If syncing
        // "nothing to report" overwrote a good bundle already on the device, one
        // bad query would block the door for everyone holding a valid ticket —
        // and it would do so invisibly, at the moment the network disappears.
        if ($request->validTickets === [] && $request->revokedTickets === []) {
            return BundleDecision::denied(
                BundleDecision::NO_CONTENT,
                'the bundle would be empty; the device keeps the bundle it already has '
                . 'rather than replacing it with one that admits nobody.'
            );
        }

        $seen = [];
        foreach ($request->allTickets() as $ticket) {
            if (isset($seen[$ticket->publicId])) {
                return BundleDecision::denied(
                    BundleDecision::CONTRADICTORY_TICKET,
                    sprintf(
                        'ticket "%s" appears more than once (%s and %s); the device would '
                        . 'be told two different things about one QR code.',
                        $ticket->publicId,
                        $seen[$ticket->publicId],
                        $ticket->status
                    )
                );
            }

            $seen[$ticket->publicId] = $ticket->status;
        }

        if ($request->sessionId !== null) {
            foreach ($request->allTickets() as $ticket) {
                if ($ticket->sessionId !== $request->sessionId) {
                    return BundleDecision::denied(
                        BundleDecision::WRONG_SESSION,
                        sprintf(
                            'ticket "%s" belongs to session %d but the bundle covers session %d.',
                            $ticket->publicId,
                            $ticket->sessionId,
                            $request->sessionId
                        )
                    );
                }
            }
        }

        return BundleDecision::allowed();
    }

    public function build(OfflineBundleRequest $request): OfflineBundlePlan
    {
        $decision = $this->decide($request);

        if (! $decision->isAllowed()) {
            throw new DomainRuleViolation(
                $decision->reason ?? 'the bundle request was refused.',
                'BUNDLE_REFUSED',
                ['verdict' => $decision->verdict]
            );
        }

        $valid = $this->sortedByPublicId($request->validTickets);
        $revoked = $this->sortedByPublicId($request->revokedTickets);

        $payload = [
            'version' => $request->schemaVersion,
            'device' => $request->device->publicId,
            'org' => $request->organizationId,
            'event' => $request->eventId,
            'session' => $request->sessionId,
            'key' => $request->publicKeyFingerprint,
            'valid' => array_map(static fn (BundleTicket $t): array => $t->jsonSerialize(), $valid),
            'revoked' => array_map(static fn (BundleTicket $t): array => $t->jsonSerialize(), $revoked),
        ];

        $canonical = json_encode(
            $payload,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );

        return new OfflineBundlePlan(
            bundleHash: hash('sha256', $canonical),
            publicKeyFingerprint: $request->publicKeyFingerprint,
            schemaVersion: $request->schemaVersion,
            ticketCount: count($valid),
            revokedCount: count($revoked),
            payload: $payload,
            expiresAt: $this->expiryFor($request),
            generatedAt: $request->generatedAt,
        );
    }

    /**
     * The earlier of the two bounds. Never null, even though `expires_at` allows
     * it — a null expiry is an admission authority with no end date.
     */
    public function expiryFor(OfflineBundleRequest $request): \DateTimeImmutable
    {
        $hardCap = $request->generatedAt->modify(sprintf('+%d hours', self::MAX_VALIDITY_HOURS));

        $candidate = $request->sessionEndsAt !== null
            ? $request->sessionEndsAt->modify(sprintf('+%d minutes', self::SESSION_GRACE_MINUTES))
            : $request->generatedAt->modify(sprintf('+%d hours', self::DEFAULT_VALIDITY_HOURS));

        return $candidate < $hardCap ? $candidate : $hardCap;
    }

    /**
     * Sorting is part of the contract, not cosmetic: the hash covers the payload,
     * so two builds of the same ticket set must produce the same bytes or
     * deduplication silently stops working and the unique key starts rejecting
     * legitimate regeneration.
     *
     * @param  list<BundleTicket> $tickets
     * @return list<BundleTicket>
     */
    private function sortedByPublicId(array $tickets): array
    {
        usort($tickets, static fn (BundleTicket $a, BundleTicket $b): int => strcmp($a->publicId, $b->publicId));

        return $tickets;
    }
}
