<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Tickets\Domain\CheckinEvaluator;
use Nabilet\Modules\Tickets\Domain\ScanMode;
use Nabilet\Modules\Tickets\Domain\ScanOutcome;
use Nabilet\Modules\Tickets\Domain\ScanRequest;
use Nabilet\Modules\Tickets\Domain\TicketSnapshot;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * Door logic.
 *
 * The interesting failures here are not "valid ticket, let them in" — that is one
 * line. They are the cases where the server is no longer the one deciding:
 *   - a ticket scanned twice (must explain, not fail)
 *   - a device that was offline and has already let someone through
 *   - a ticket that was refunded between the bundle being built and the door
 *
 * Every one of those has a "comfortable" wrong answer that looks fine in the log
 * and is wrong in the reconciliation report weeks later.
 */
final class CheckinEvaluatorTest extends TestCase
{
    private const SESSION = 42;

    private function evaluator(): CheckinEvaluator
    {
        return new CheckinEvaluator();
    }

    private function ticket(
        string $status,
        ?\DateTimeImmutable $usedAt = null,
        ?string $revokedReason = null,
        ?\DateTimeImmutable $refundedAt = null,
    ): TicketSnapshot {
        return new TicketSnapshot(
            publicId: 'tkt_01',
            status: $status,
            sessionId: self::SESSION,
            eventId: 7,
            ticketNumber: 'NB-0001',
            usedAt: $usedAt,
            revokedAt: null,
            cancelledAt: null,
            refundedAt: $refundedAt,
            expiredAt: null,
            revokedReason: $revokedReason,
        );
    }

    private function scan(string $mode = ScanMode::ONLINE, bool $deviceAdmitted = false, ?string $clientScanId = null): ScanRequest
    {
        return new ScanRequest(
            ticketPublicId: 'tkt_01',
            sessionId: self::SESSION,
            mode: $mode,
            deviceId: 3,
            clientScanId: $clientScanId,
            deviceAdmitted: $deviceAdmitted,
        );
    }

    // ── online ───────────────────────────────────────────────────────────────

    public function testIssuedTicketIsAdmitted(): void
    {
        $outcome = $this->evaluator()->evaluate($this->scan(), $this->ticket(TicketStateMachine::ISSUED));

        $this->assertTrue($outcome->admits);
        $this->assertSame(ScanOutcome::ADMITTED, $outcome->result);
        $this->assertSame(TicketStateMachine::USED, $outcome->resultingStatus);
    }

    /**
     * ТЗ §32: a second scan is not an error and not a bare denial. The operator
     * is shown when the ticket was first scanned — "used at 19:04 at Gate B" is
     * what resolves the argument at the door.
     */
    public function testSecondScanReturnsAlreadyUsedWithTheOriginalTimestamp(): void
    {
        $usedAt = new \DateTimeImmutable('2026-09-20 19:04:00');

        $outcome = $this->evaluator()->evaluate(
            $this->scan(),
            $this->ticket(TicketStateMachine::USED, $usedAt)
        );

        $this->assertFalse($outcome->admits);
        $this->assertSame(ScanOutcome::ALREADY_USED, $outcome->result);
        $this->assertNotNull($outcome->usedAt);
        $this->assertSame('2026-09-20 19:04:00', $outcome->usedAt->format('Y-m-d H:i:s'));
        // A re-scan must not change the ticket again.
        $this->assertNull($outcome->resultingStatus);
    }

    /** @return array<string, array{string, string}> */
    public function invalidStatuses(): array
    {
        return [
            'revoked' => [TicketStateMachine::REVOKED, ScanOutcome::REVOKED],
            'cancelled' => [TicketStateMachine::CANCELLED, ScanOutcome::CANCELLED],
            'refunded' => [TicketStateMachine::REFUNDED, ScanOutcome::REFUNDED],
            'expired' => [TicketStateMachine::EXPIRED, ScanOutcome::EXPIRED],
        ];
    }

    public function testInvalidTicketsAreRefusedWithADistinctResultEach(): void
    {
        foreach ($this->invalidStatuses() as [$status, $expected]) {
            $outcome = $this->evaluator()->evaluate($this->scan(), $this->ticket($status));

            $this->assertSame($expected, $outcome->result, $status);
            $this->assertFalse($outcome->admits, $status);
            $this->assertNull($outcome->resultingStatus, $status);
        }
    }

    public function testRevokedCarriesItsReason(): void
    {
        $outcome = $this->evaluator()->evaluate(
            $this->scan(),
            $this->ticket(TicketStateMachine::REVOKED, null, 'chargeback')
        );

        $this->assertSame('chargeback', $outcome->reason);
    }

    public function testUnknownQrIsNotFound(): void
    {
        $outcome = $this->evaluator()->evaluate($this->scan(), null);

        $this->assertSame(ScanOutcome::NOT_FOUND, $outcome->result);
        $this->assertFalse($outcome->admits);
    }

    /**
     * A ticket for another session is not "invalid" — calling it revoked would
     * send staff hunting for fraud when it is simply the wrong evening.
     */
    public function testTicketFromAnotherSessionIsWrongSession(): void
    {
        $foreign = new TicketSnapshot(
            publicId: 'tkt_01',
            status: TicketStateMachine::ISSUED,
            sessionId: 999,
            eventId: 7,
        );

        $outcome = $this->evaluator()->evaluate($this->scan(), $foreign);

        $this->assertSame(ScanOutcome::WRONG_SESSION, $outcome->result);
        $this->assertFalse($outcome->admits);
        $this->assertNull($outcome->resultingStatus);
    }

    // ── offline sync (§43 / §44) ─────────────────────────────────────────────

    public function testOfflineAdmissionOfAValidTicketBecomesUsed(): void
    {
        $outcome = $this->evaluator()->evaluate(
            $this->scan(ScanMode::OFFLINE_SYNC, true, 'scan-uuid-1'),
            $this->ticket(TicketStateMachine::ISSUED)
        );

        $this->assertSame(ScanOutcome::ADMITTED, $outcome->result);
        $this->assertTrue($outcome->admits);
        $this->assertSame(TicketStateMachine::USED, $outcome->resultingStatus);
    }

    /**
     * §44: the device already let the person in, and the ticket was refunded in
     * the meantime. Nobody can be ejected, so the ticket is REVOKED — not marked
     * `used`, which would report a clean entry for a refunded ticket.
     */
    public function testOfflineAdmissionOfARefundedTicketIsResolvedByRevocation(): void
    {
        $outcome = $this->evaluator()->evaluate(
            $this->scan(ScanMode::OFFLINE_SYNC, true, 'scan-uuid-2'),
            $this->ticket(TicketStateMachine::REFUNDED)
        );

        $this->assertSame(ScanOutcome::CONFLICT_REVOKED, $outcome->result);
        $this->assertFalse($outcome->admits);
        $this->assertSame(TicketStateMachine::REVOKED, $outcome->resultingStatus);
    }

    public function testOfflineAdmissionOfAnAlreadyUsedTicketIsAConflict(): void
    {
        // Two people waved through on one ticket: the ticket had already entered
        // before the offline device scanned it.
        $outcome = $this->evaluator()->evaluate(
            $this->scan(ScanMode::OFFLINE_SYNC, true, 'scan-uuid-3'),
            $this->ticket(TicketStateMachine::USED, new \DateTimeImmutable('2026-09-20 19:00:00'))
        );

        $this->assertSame(ScanOutcome::CONFLICT_REVOKED, $outcome->result);
        $this->assertSame(TicketStateMachine::REVOKED, $outcome->resultingStatus);
    }

    public function testOfflineAdmissionOfACancelledTicketIsAConflict(): void
    {
        $outcome = $this->evaluator()->evaluate(
            $this->scan(ScanMode::OFFLINE_SYNC, true, 'scan-uuid-4'),
            $this->ticket(TicketStateMachine::CANCELLED)
        );

        $this->assertSame(ScanOutcome::CONFLICT_REVOKED, $outcome->result);
    }

    /**
     * The device refused. Nobody entered, the ticket is untouched — but the scan
     * is recorded, because a refusal of a VALID ticket means the bundle was
     * stale and the sale happened after the device last synced.
     */
    public function testDeviceRefusalIsRecordedWithoutChangingTheTicket(): void
    {
        $outcome = $this->evaluator()->evaluate(
            $this->scan(ScanMode::OFFLINE_SYNC, false, 'scan-uuid-5'),
            $this->ticket(TicketStateMachine::ISSUED)
        );

        $this->assertSame(ScanOutcome::REFUSED_BY_DEVICE, $outcome->result);
        $this->assertFalse($outcome->admits);
        $this->assertNull($outcome->resultingStatus);
        $this->assertNotNull($outcome->reason);
    }

    // ── the idempotency guard ────────────────────────────────────────────────

    public function testOfflineSyncWithoutClientScanIdIsRejected(): void
    {
        // Without it a retried upload is indistinguishable from a second admission.
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new ScanRequest(
                ticketPublicId: 'tkt_01',
                sessionId: self::SESSION,
                mode: ScanMode::OFFLINE_SYNC,
                deviceId: 3,
            )
        );
    }

    public function testUnknownScanModeIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new ScanRequest(
                ticketPublicId: 'tkt_01',
                sessionId: self::SESSION,
                mode: 'carrier_pigeon',
            )
        );
    }

    // ── PII ──────────────────────────────────────────────────────────────────

    /**
     * ТЗ §43/§44: the QR and the offline bundle carry no personal data. The
     * snapshot is the object that travels to the device, so it must not have
     * anywhere to PUT a holder name.
     */
    public function testTicketSnapshotHasNoPersonalData(): void
    {
        $snapshot = $this->ticket(TicketStateMachine::ISSUED);

        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($snapshot))->getProperties()
        );

        foreach (['holderName', 'holder_name', 'email', 'phone'] as $forbidden) {
            $this->assertNotContains($forbidden, $properties);
        }
    }

    public function testOutcomeSerializesForTheApi(): void
    {
        $json = $this->evaluator()->evaluate($this->scan(), $this->ticket(TicketStateMachine::ISSUED))
            ->jsonSerialize();

        $this->assertSame('admitted', $json['result']);
        $this->assertTrue($json['admits']);
        $this->assertSame('used', $json['resulting_status']);
    }
}
