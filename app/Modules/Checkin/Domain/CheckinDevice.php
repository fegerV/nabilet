<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A device authorised to run the Android Checker (ТЗ §31/§33).
 *
 * `checkin_devices.status` — словарь из схемы: `ck_checkin_devices_status`
 * допускает ровно `active`, `disabled`, `revoked` (spec, сплит
 * `2026_10_12_000500_add_status_vocabularies.php`). Этот докблок утверждал
 * обратное — «CHECK нет, колонка из 19 свободных по §3.11» — и был неверен
 * с момента появления ограничения. Значения ниже совпадают с ним до последнего
 * символа, так что расходились только обоснования: читатель верил, что четвёртое
 * состояние добавить безопасно, и был прав ровно до первого обращения к базе.
 *
 * Fail-closed сохранён и теперь барьер двойной: только `active` получает бандл,
 * любое другое значение — включая то, которого класс не знает, — считается
 * неавторизованным. Направление важно. Если будущий релиз введёт `blocked`, а
 * этот класс по-прежнему знает только `active`, устройству откажут — это
 * безопасный исход. Обратное («всё кроме revoked разрешено») отдало бы бандл
 * устройству, статус которого никто не распознаёт.
 *
 * ЧТО ИЗ ЭТИХ ТРЁХ ЗНАЧЕНИЙ РЕАЛЬНО ПИШЕТСЯ. `active` — при выдаче токена
 * (`DeviceTokenIssuer::issue()`), `revoked` — при отзыве
 * (`DeviceTokenIssuer::revoke()` и `PATCH /admin/checkin/devices/{device}`).
 * `disabled` НЕ ПИШЕТСЯ НИКОГДА: в проверке `AuthenticateChecker` он ведёт себя
 * ровно так же, как `revoked` (всё, что не `active`, — 401), а вернуть
 * устройство из него было бы нечем. Значение оставлено в классе, потому что оно
 * есть в CHECK и потому что распознать его нужно: устройство с `disabled` в
 * базе (поставлено скриптом, миграцией, будущим релизом) обязано получить
 * отказ, а не 500 из-за неизвестного статуса. Тест
 * `OfflineBundleTest::testADisabledDeviceIsRefused()` подаёт `STATUS_DISABLED`
 * напрямую и закрепляет именно этот отказ — то есть значение проверено, хотя и
 * не записывается.
 *
 * Отзыв НЕОБРАТИМ, и это свойство кода, а не пожелание: `revoke()` меняет только
 * статус, `device_token_hash` остаётся прежним, поэтому возврат `active`
 * воскресил бы утёкший токен. `PATCH` принимает только `revoked`; оживить
 * устройство может лишь новая выдача токена — у неё новый хеш.
 */
final class CheckinDevice
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $status = self::STATUS_ACTIVE,
    ) {
        if ($id <= 0) {
            throw new DomainRuleViolation(
                sprintf('A check-in device needs a positive id, got %d.', $id),
                'INVALID_DEVICE'
            );
        }

        if ($publicId === '') {
            throw new DomainRuleViolation(
                'A check-in device needs its public id: it is part of the bundle hash.',
                'INVALID_DEVICE'
            );
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
