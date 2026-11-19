<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Services;

use Illuminate\Support\Str;
use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Models\KioskDevice;

/**
 * Единственная точка выдачи и ротации токенов информационных киосков.
 *
 * Префикс `nbks_` позволяет отличить киоск от чекера (`nbck_`) и пользователя
 * в логах и при ротации. В базе хранится только sha256 — токен возвращается
 * ровно один раз в ответе на создание/ротацию.
 *
 * Ротация создаёт НОВУЮ строку устройства, а не перезаписывает хеш старой:
 * старый токен должен немедленно перестать работать, а история продаж
 * (`kiosk_sales.kiosk_device_id`) обязана остаться привязанной к тому
 * устройству, которое её совершило.
 */
final class KioskTokenIssuer
{
    public const TOKEN_PREFIX = 'nbks_';

    /** Секретная часть: 48 символов base62 ≈ 285 бит. */
    private const TOKEN_BODY_LENGTH = 48;

    /**
     * @return array{device: KioskDevice, token: string}
     */
    public function issue(
        int $organizationId,
        string $name,
        ?string $locationAddress = null,
        ?string $locationNote = null,
        string $platform = 'web_kiosk',
        ?string $appVersion = null,
        bool $salesEnabled = false,
    ): array {
        $token = self::TOKEN_PREFIX . Str::random(self::TOKEN_BODY_LENGTH);

        $device = KioskDevice::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'location_address' => $locationAddress,
            'location_note' => $locationNote,
            'platform' => $platform,
            'app_version' => $appVersion,
            // Явный статус, а не доверие дефолту колонки (как у чекера).
            'status' => DevicePolicy::STATUS_ACTIVE,
            'sales_enabled' => $salesEnabled,
            'update_status' => DevicePolicy::UPDATE_NONE,
            'kiosk_token_hash' => self::hash($token),
            'cart_token' => (string) Str::uuid(),
        ]);

        return ['device' => $device, 'token' => $token];
    }

    /**
     * Ротация: старое устройство помечается revoked, новое создаётся с тем же
     * местоположением и флагом продаж. Возвращает новый токен один раз.
     *
     * @return array{device: KioskDevice, token: string}
     */
    public function rotate(KioskDevice $device): array
    {
        $device->forceFill([
            'status' => DevicePolicy::STATUS_REVOKED,
            'revoked_at' => now(),
            'sales_enabled' => false,
        ])->save();

        return $this->issue(
            organizationId: (int) $device->organization_id,
            name: (string) $device->name,
            locationAddress: $device->location_address,
            locationNote: $device->location_note,
            platform: (string) ($device->platform ?? 'web_kiosk'),
            appVersion: $device->app_version,
            salesEnabled: false,
        );
    }

    public function revoke(KioskDevice $device): KioskDevice
    {
        // Статус, а не удаление: kiosk_sales ссылается на устройство.
        $device->forceFill([
            'status' => DevicePolicy::STATUS_REVOKED,
            'revoked_at' => now(),
            'sales_enabled' => false,
        ])->save();

        return $device->fresh();
    }

    public static function hash(string $token): string
    {
        return Sha256Hex::of($token);
    }

    /** Отметка «устройство на связи». Ошибка БД не должна ронять продажу. */
    public function touch(KioskDevice $device): void
    {
        try {
            $device->forceFill(['last_seen_at' => now()])->save();
        } catch (\Throwable) {
            // Намеренно проглочено: аудит доступности важнее самой продажи.
        }
    }
}
