<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Политика статуса устройства киоска.
 *
 * Словарь совпадает с `ck_kiosk_devices_status` и с политикой чекера:
 * разрешён ТОЛЬКО `active`. `disabled` и `revoked` — отказ, причём unknown
 * статус тоже отказ (fail-closed). Отзыв необратим: токен остаётся в базе
 * только хешем, поэтому «оживить» устройство с тем же хешем нельзя — нужна
 * новая выдача токена.
 *
 * `sales_enabled` намеренно НЕ часть статуса: администратор выключает
 * продажи, оставляя киоск на связи. Это позволяет дистанционно остановить
 * продажу, не теряя канал управления и не делая вид, что устройство сломано.
 */
final class KioskDevice
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED = 'revoked';

    public const UPDATE_NONE = 'none';
    public const UPDATE_PENDING = 'pending';
    public const UPDATE_APPLIED = 'applied';
    public const UPDATE_FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly bool $salesEnabled = false,
    ) {
        if ($id <= 0) {
            throw new DomainRuleViolation(
                sprintf('A kiosk device needs a positive id, got %d.', $id),
                'INVALID_KIOSK_DEVICE'
            );
        }

        if ($publicId === '') {
            throw new DomainRuleViolation(
                'A kiosk device needs its public id.',
                'INVALID_KIOSK_DEVICE'
            );
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function maySell(): bool
    {
        return $this->isActive() && $this->salesEnabled;
    }
}
