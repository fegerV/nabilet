<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Kiosk\Domain\KioskDevice;
use Nabilet\Modules\Kiosk\Domain\KioskSale;
use Nabilet\Modules\Kiosk\Services\KioskTokenIssuer;
use Nabilet\Tests\Support\TestCase;

/**
 * Домен киоска без Laravel и БД.
 *
 * Проверяет два инварианта, которые легко сломать при добавлении кнопки в
 * админке: (1) только `active` устройство допускается, продажи включаются
 * отдельным флагом; (2) статус продажи не может «прыгнуть» мимо оплаты к
 * выданному билету — QR подписывается только после webhook'а.
 */
final class KioskPolicyTest extends TestCase
{
    public function testOnlyActiveDeviceIsAdmitted(): void
    {
        $active = new KioskDevice(1, '01ABC', KioskDevice::STATUS_ACTIVE, true);
        $disabled = new KioskDevice(2, '01ABD', KioskDevice::STATUS_DISABLED, true);
        $revoked = new KioskDevice(3, '01ABE', KioskDevice::STATUS_REVOKED, true);

        $this->assertTrue($active->isActive());
        $this->assertTrue($active->maySell());
        $this->assertFalse($disabled->isActive());
        $this->assertFalse($disabled->maySell());
        $this->assertFalse($revoked->maySell());
    }

    public function testSalesFlagAloneDoesNotAuthoriseRevokedDevice(): void
    {
        $device = new KioskDevice(1, '01ABC', KioskDevice::STATUS_REVOKED, true);

        $this->assertFalse($device->maySell(), 'отозванное устройство не продаёт даже при включённом флаге');
    }

    public function testUnknownStatusIsRefusedFailClosed(): void
    {
        $device = new KioskDevice(1, '01ABC', 'blocked', true);

        $this->assertFalse($device->isActive());
        $this->assertFalse($device->maySell());
    }

    public function testInvalidIdentityIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new KioskDevice(0, '01ABC')
        );
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new KioskDevice(1, '')
        );
    }

    public function testSaleCannotJumpStraightToIssued(): void
    {
        $this->assertFalse(KioskSale::can(KioskSale::STATUS_PENDING, KioskSale::STATUS_ISSUED));
        $this->assertFalse(KioskSale::can(KioskSale::STATUS_PENDING, KioskSale::STATUS_PAID));
        $this->assertTrue(KioskSale::can(KioskSale::STATUS_PENDING, KioskSale::STATUS_AWAITING_PAYMENT));
        $this->assertTrue(KioskSale::can(KioskSale::STATUS_AWAITING_PAYMENT, KioskSale::STATUS_PAID));
        $this->assertTrue(KioskSale::can(KioskSale::STATUS_PAID, KioskSale::STATUS_ISSUED));
    }

    public function testTerminalSaleStatesCannotBeResurrected(): void
    {
        $this->assertTrue(KioskSale::isTerminal(KioskSale::STATUS_FAILED));
        $this->assertTrue(KioskSale::isTerminal(KioskSale::STATUS_REFUNDED));
        $this->assertFalse(KioskSale::isTerminal(KioskSale::STATUS_ISSUED));
        $this->assertFalse(KioskSale::can(KioskSale::STATUS_REFUNDED, KioskSale::STATUS_ISSUED));
    }

    public function testTokenPrefixIsDistinctFromChecker(): void
    {
        $this->assertSame('nbks_', KioskTokenIssuer::TOKEN_PREFIX);
        $this->assertSame(hash('sha256', 'nbks_test'), KioskTokenIssuer::hash('nbks_test'));
    }
}
