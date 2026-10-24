<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Services;

use Illuminate\Support\Str;
use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Modules\Checkin\Domain\CheckinDevice as DevicePolicy;
use Nabilet\Modules\Tickets\Models\CheckinDevice;

/**
 * Единственная точка выдачи токенов устройствам контроля входа.
 *
 * ЗАЧЕМ ОН ВООБЩЕ ПОЯВИЛСЯ
 *   `checkin_devices.device_token_hash` существовал с самого начала спеки, но
 *   НИ ОДИН код его не писал: не было ни выдачи токена, ни проверки. То есть
 *   колонка с уникальным индексом описывала возможность, которой не было —
 *   устройства создавались только прямым SQL. Пока issuer'а нет, guard
 *   `checkerAuth` нечем проверить, поэтому этот класс и middleware
 *   `AuthenticateChecker` — две половины одного контура.
 *
 * ПОЧЕМУ ТОКЕН ПОКАЗЫВАЕТСЯ ОДИН РАЗ
 *   Хранится только sha256-дайджест (`DeviceTokenIssuer::hash`), сравнивается он
 *   же. Вернуть токен повторно невозможно в принципе: потеря — отзыв устройства
 *   и выпуск нового (`PATCH /admin/checkin/devices/{device}` со статусом
 *   `revoked`, затем новый POST).
 *
 * ПРЕФИКС `nbck_`
 *   Чтобы токен устройства было видно в логах, grep'ом и при ротации. Секрет
 *   начинается после префикса, и длина всей строки — 53 символа, что влезает в
 *   `Authorization` и в поле ввода мобильного приложения.
 */
final class DeviceTokenIssuer
{
    public const TOKEN_PREFIX = 'nbck_';

    /** Секретная часть: 48 символов base62 ≈ 285 бит. */
    private const TOKEN_BODY_LENGTH = 48;

    /**
     * @return array{device: CheckinDevice, token: string}
     */
    public function issue(
        int $organizationId,
        string $name,
        string $platform = 'android',
        ?string $appVersion = null
    ): array {
        $token = self::TOKEN_PREFIX . Str::random(self::TOKEN_BODY_LENGTH);

        $device = CheckinDevice::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'platform' => $platform,
            'app_version' => $appVersion,
            // Явный `active`, а не дефолт колонки: словарь статуса с миграции
            // 017 заперт CHECK'ом, и молчаливое доверие дефолту базы здесь
            // означало бы, что колонка может уехать, а выдача — нет.
            'status' => DevicePolicy::STATUS_ACTIVE,
            'device_token_hash' => self::hash($token),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    /**
     * Отзыв: токен перестаёт работать без удаления истории сканов.
     *
     * Именно статус, а не удаление строки: `ticket_scans.device_id` ссылается на
     * устройство, и `ON DELETE` оторвал бы журнал входов от того, кто его вёл —
     * аудит перестал бы отвечать на вопрос «кем принят билет».
     */
    public function revoke(CheckinDevice $device): CheckinDevice
    {
        $device->update(['status' => DevicePolicy::STATUS_REVOKED]);

        return $device->fresh();
    }

    /**
     * Отпечаток токена устройства — тем же правилом, что и остальные шесть
     * `CHAR(64)` в схеме.
     *
     * Метод остаётся точкой входа для этого класса (его зовут middleware,
     * контроллер бандла и запись в `checkin_devices`), но алгоритм берётся у
     * владельца. Раньше `hash('sha256', …)` был выписан здесь, в
     * `AuthenticateChecker` и в `OfflineBundleController` — и три копии должны
     * были совпадать молча: разойдясь, они дали бы 401 на живом сканере.
     */
    public static function hash(string $token): string
    {
        return Sha256Hex::of($token);
    }

    /**
     * Отметка «устройство живое» (`checkin_devices.last_seen_at`).
     *
     * Колонка существовала без единого писателя: в списке устройств админки она
     * была всегда NULL, и вопрос «какой из сканеров на входе сломался» нельзя
     * было отличить от «сканнер просто стоит». Пишется на каждый из трёх
     * эндпоинтов чекина.
     *
     * МОЛЧА ПРИ ОШИБКЕ. Аудит доступности не должен ронять погашение билета:
     * если БД на мгновение отказала, вход людей важнее, чем отметка времени.
     * Поэтому `try/catch`, а не проброс исключения.
     */
    public function touch(CheckinDevice $device): void
    {
        try {
            $device->forceFill(['last_seen_at' => now()])->save();
        } catch (\Throwable) {
            // Намеренно проглочено: см. докблок.
        }
    }
}
