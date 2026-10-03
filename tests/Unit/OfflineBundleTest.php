<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Checkin\Domain\BundleDecision;
use Nabilet\Modules\Checkin\Domain\BundleFreshness;
use Nabilet\Modules\Checkin\Domain\BundleTicket;
use Nabilet\Modules\Checkin\Domain\CheckinDevice;
use Nabilet\Modules\Checkin\Domain\OfflineBundleBuilder;
use Nabilet\Modules\Checkin\Domain\OfflineBundleRequest;
use Nabilet\Modules\Tickets\Domain\TicketSnapshot;
use Nabilet\Tests\Support\TestCase;

/**
 * Offline bundles for the Android Checker (ТЗ §43/§44, §33).
 *
 * The bundle is an admission authority: offline, the device decides and the
 * server only reconciles afterwards. So the properties that matter are not
 * "does it serialize" but "can two devices both have one", "does it stop being
 * an authority", and "can it be trusted to say what it contains".
 */
final class OfflineBundleTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    private function ticket(string $publicId, string $status = 'issued', int $sessionId = 500): BundleTicket
    {
        return new BundleTicket($publicId, 'T-' . $publicId, $status, $sessionId);
    }

    private function device(string $publicId = '01JDEV00000000000000000001', string $status = CheckinDevice::STATUS_ACTIVE): CheckinDevice
    {
        return new CheckinDevice(7, $publicId, $status);
    }

    /** @param list<BundleTicket> $valid */
    /** @param list<BundleTicket> $revoked */
    private function request(
        array $valid,
        array $revoked = [],
        ?CheckinDevice $device = null,
        ?int $sessionId = 500,
        string $key = 'SHA256:abcd1234',
        ?\DateTimeImmutable $endsAt = null,
    ): OfflineBundleRequest {
        return new OfflineBundleRequest(
            device: $device ?? $this->device(),
            organizationId: 1,
            eventId: 42,
            sessionId: $sessionId,
            validTickets: $valid,
            revokedTickets: $revoked,
            publicKeyFingerprint: $key,
            generatedAt: $this->now,
            sessionEndsAt: $endsAt,
        );
    }

    // ── the hash must be device-scoped ───────────────────────────────────────

    /**
     * `uq_offline_bundles_hash` is unique with no device qualifier. Two devices
     * covering one session get identical content, so a content-only hash makes
     * the second device's INSERT fail with ER_DUP_ENTRY. Verified against
     * MySQL 8.4 before this rule was written.
     */
    public function testTwoDevicesWithIdenticalContentGetDifferentHashes(): void
    {
        $builder = new OfflineBundleBuilder();

        $a = $builder->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            device: $this->device('01JDEV00000000000000000001'),
        ));
        $b = $builder->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            device: $this->device('01JDEV00000000000000000002'),
        ));

        $this->assertNotSame($a->bundleHash, $b->bundleHash);
    }

    /** The other half: one device asking twice must collide, so it can reuse. */
    public function testSameDeviceAndSameContentHashesIdentically(): void
    {
        $builder = new OfflineBundleBuilder();

        $first = $builder->build($this->request([$this->ticket('01JT0000000000000000000001')]));
        $second = $builder->build($this->request([$this->ticket('01JT0000000000000000000001')]));

        $this->assertSame($first->bundleHash, $second->bundleHash);
    }

    /** Order must not matter, or dedup silently stops working. */
    public function testTicketOrderDoesNotChangeTheHash(): void
    {
        $builder = new OfflineBundleBuilder();

        $a = $builder->build($this->request([
            $this->ticket('01JT0000000000000000000003'),
            $this->ticket('01JT0000000000000000000001'),
            $this->ticket('01JT0000000000000000000002'),
        ]));
        $b = $builder->build($this->request([
            $this->ticket('01JT0000000000000000000001'),
            $this->ticket('01JT0000000000000000000002'),
            $this->ticket('01JT0000000000000000000003'),
        ]));

        $this->assertSame($a->bundleHash, $b->bundleHash);
    }

    /** `bundle_hash` is CHAR(64). */
    public function testHashFitsTheColumn(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request([$this->ticket('01JT0000000000000000000001')]));

        $this->assertSame(64, strlen($plan->bundleHash));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $plan->bundleHash));
    }

    /** What the device receives must be byte-for-byte what was hashed. */
    public function testPayloadJsonReHashesToTheSameValue(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            [$this->ticket('01JT0000000000000000000009', 'revoked')],
        ));

        $this->assertSame($plan->bundleHash, hash('sha256', $plan->payloadJson()));
    }

    // ── counts are derived, never trusted ────────────────────────────────────

    public function testCountsAreDerivedFromTheLists(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [
                $this->ticket('01JT0000000000000000000001'),
                $this->ticket('01JT0000000000000000000002'),
                $this->ticket('01JT0000000000000000000003'),
            ],
            [$this->ticket('01JT0000000000000000000009', 'revoked')],
        ));

        $this->assertSame(3, $plan->ticketCount);
        $this->assertSame(1, $plan->revokedCount);
        $this->assertSame(4, $plan->totalCount());
    }

    /** Revoked tickets are carried, not dropped: the device must be able to say why. */
    public function testRevokedTicketsAreCarriedInThePayload(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            [$this->ticket('01JT0000000000000000000009', 'revoked')],
        ));

        $this->assertCount(1, $plan->payload['revoked']);
        $this->assertSame('revoked', $plan->payload['revoked'][0]['s']);
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    public function testADisabledDeviceIsRefused(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            device: $this->device(status: CheckinDevice::STATUS_DISABLED),
        ));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::DEVICE_NOT_ACTIVE, $decision->verdict);
    }

    /**
     * `checkin_devices.status` has no CHECK. An unrecognised status must refuse,
     * not pass — otherwise a status nobody knows about grants a bundle.
     */
    public function testAnUnrecognisedDeviceStatusIsRefusedFailClosed(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            device: $this->device(status: 'blocked'),
        ));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::DEVICE_NOT_ACTIVE, $decision->verdict);
    }

    public function testAMissingPublicKeyFingerprintIsRefused(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            key: '',
        ));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::MISSING_PUBLIC_KEY, $decision->verdict);
    }

    /**
     * An empty bundle must not replace a good one already on the device, or one
     * bad query blocks the door for everyone holding a valid ticket.
     */
    public function testAnEmptyBundleIsRefusedSoItCannotWipeTheDevicesCopy(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request([], []));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::NO_CONTENT, $decision->verdict);
    }

    /** Revoked-only is content: refusing everyone is a legitimate state. */
    public function testABundleOfOnlyRevokedTicketsIsAllowed(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [],
            [$this->ticket('01JT0000000000000000000009', 'revoked')],
        ));

        $this->assertTrue($decision->isAllowed());
    }

    public function testATicketInBothListsIsRefused(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [$this->ticket('01JT0000000000000000000001', 'issued')],
            [$this->ticket('01JT0000000000000000000001', 'revoked')],
        ));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::CONTRADICTORY_TICKET, $decision->verdict);
    }

    public function testADuplicateInsideOneListIsRefused(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request([
            $this->ticket('01JT0000000000000000000001'),
            $this->ticket('01JT0000000000000000000001'),
        ]));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::CONTRADICTORY_TICKET, $decision->verdict);
    }

    public function testATicketFromAnotherSessionIsRefused(): void
    {
        $decision = (new OfflineBundleBuilder())->decide($this->request(
            [$this->ticket('01JT0000000000000000000001', 'issued', sessionId: 999)],
            sessionId: 500,
        ));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(BundleDecision::WRONG_SESSION, $decision->verdict);
    }

    public function testBuildingARefusedRequestThrows(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new OfflineBundleBuilder())->build($this->request([], []))
        );
    }

    // ── expiry ───────────────────────────────────────────────────────────────

    public function testExpiryFollowsTheSessionEndPlusGrace(): void
    {
        $endsAt = $this->now->modify('+3 hours');
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            endsAt: $endsAt,
        ));

        $expected = $endsAt->modify(sprintf('+%d minutes', OfflineBundleBuilder::SESSION_GRACE_MINUTES));
        $this->assertSame($expected->getTimestamp(), $plan->expiresAt->getTimestamp());
    }

    /** A bundle built long before the session must not still be honoured at the door. */
    public function testExpiryIsCappedEvenWhenTheSessionIsDaysAway(): void
    {
        $endsAt = $this->now->modify('+5 days');
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [$this->ticket('01JT0000000000000000000001')],
            endsAt: $endsAt,
        ));

        $cap = $this->now->modify(sprintf('+%d hours', OfflineBundleBuilder::MAX_VALIDITY_HOURS));
        $this->assertSame($cap->getTimestamp(), $plan->expiresAt->getTimestamp());
        $this->assertTrue($plan->expiresAt < $endsAt);
    }

    public function testExpiryWithoutASessionUsesTheDefaultWindow(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request(
            [$this->ticket('01JT0000000000000000000001', 'issued', sessionId: 500)],
            sessionId: null,
        ));

        $expected = $this->now->modify(sprintf('+%d hours', OfflineBundleBuilder::DEFAULT_VALIDITY_HOURS));
        $this->assertSame($expected->getTimestamp(), $plan->expiresAt->getTimestamp());
    }

    /** The column allows NULL; the plan must never produce it. */
    public function testExpiryIsNeverNull(): void
    {
        $plan = (new OfflineBundleBuilder())->build($this->request([$this->ticket('01JT0000000000000000000001')]));

        $this->assertNotNull($plan->expiresAt);
    }

    // ── freshness at reconciliation ──────────────────────────────────────────

    public function testABundleUsedBeforeItsExpiryIsCurrent(): void
    {
        $expiresAt = $this->now->modify('+2 hours');
        $freshness = BundleFreshness::of($expiresAt, $this->now->modify('+1 hour'));

        $this->assertTrue($freshness->isUsable());
        $this->assertSame(BundleFreshness::CURRENT, $freshness->state);
    }

    /** The instant of expiry is still inside; only after it is stale. */
    public function testTheExpiryInstantItselfIsStillCurrent(): void
    {
        $expiresAt = $this->now->modify('+2 hours');
        $freshness = BundleFreshness::of($expiresAt, $expiresAt);

        $this->assertTrue($freshness->isUsable());
    }

    public function testABundleUsedAfterItsExpiryIsExpired(): void
    {
        $expiresAt = $this->now->modify('+2 hours');
        $freshness = BundleFreshness::of($expiresAt, $this->now->modify('+3 hours'));

        $this->assertFalse($freshness->isUsable());
        $this->assertSame(BundleFreshness::EXPIRED, $freshness->state);
        $this->assertNotNull($freshness->reason);
    }

    /** A null expiry is an authority with no end; it is not "current". */
    public function testABundleWithNoExpiryIsReportedAsUnbounded(): void
    {
        $freshness = BundleFreshness::of(null, $this->now);

        $this->assertFalse($freshness->isUsable());
        $this->assertSame(BundleFreshness::UNBOUNDED, $freshness->state);
    }

    // ── no personal data ─────────────────────────────────────────────────────

    /**
     * The bundle travels to an untrusted device. A holder name would end up
     * cached on a lost phone, so the wire shape simply has nowhere to put one.
     */
    public function testABundleTicketCarriesOnlyIdNumberAndStatus(): void
    {
        $ticket = $this->ticket('01JT0000000000000000000001');
        $encoded = json_encode($ticket);

        $this->assertSame('{"id":"01JT0000000000000000000001","n":"T-01JT0000000000000000000001","s":"issued"}', $encoded);
    }

    public function testFromSnapshotProducesTheSameShape(): void
    {
        $snapshot = new TicketSnapshot('01JT0000000000000000000001', 'issued', 500, 42, 'T-1');

        $ticket = BundleTicket::fromSnapshot($snapshot);

        $this->assertSame('01JT0000000000000000000001', $ticket->publicId);
        $this->assertSame('T-1', $ticket->number);
        $this->assertSame(500, $ticket->sessionId);
    }

    // ── value object guards ──────────────────────────────────────────────────

    public function testABundleTicketRejectsAnEmptyPublicId(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->ticket('')
        );
    }

    public function testABundleTicketRejectsAnUnscopedSession(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->ticket('01JT0000000000000000000001', 'issued', 0)
        );
    }

    public function testADeviceRejectsAnEmptyPublicId(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->device('')
        );
    }

    public function testARequestRejectsNonTicketEntries(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->request(['not-a-ticket'])
        );
    }
}
