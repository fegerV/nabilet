<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Checkin\Domain\CheckinDevice as DevicePolicy;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Symfony\Component\HttpFoundation\Response;

/**
 * `checkerAuth` — контроль входа по bearer-токену УСТРОЙСТВА, не пользователя
 * (openapi.yaml: `security: [{ checkerAuth: [] }]` на /api/v1/checkin/*).
 *
 * ПОЧЕМУ ЭТО НЕ `auth:api`
 *   На входе стоит телефон под shared-аккаунтом оператора, а иногда и вовсе без
 *   него. Сессия пользователя живёт часы и утекает вместе с токеном; устройство
 *   можно отозвать одно, не выкидывая из смены весь персонал. Проверка идёт по
 *   `checkin_devices.device_token_hash` — см. `DeviceTokenIssuer`.
 *
 * ПОЧЕМУ ЭТО FAIL-CLOSED ИМЕННО ТАК
 *   Разрешён только статус `active`. `revoked` и `disabled` — отказ, и не
 *   «мягкий»: токен устройства остаётся валидной строкой, но не пропускает
 *   ни один из трёх эндпоинтов. Обратное правило («всё, кроме revoked»)
 *   пустило бы внутрь устройство в статусе, которого никто не recognize'ит —
 *   ровно та же логика, что в `CheckinDevice::isActive()`.
 *
 * ПОЧЕМУ КОНТЕКСТ ОРГАНИЗАЦИИ БЕРЁТСЯ У УСТРОЙСТВА, А НЕ У ХОСТА
 *   `ResolveOrganizationContext` (global) до этого мог выставить организацию по
 *   поддомену белого ярлыка. Для запроса от устройства приоритет у токена:
 *   организация, которой принадлежит устройство, — это и есть тенант сканов.
 *
 * ПОЧЕМУ АЛИАСА `checkerAuth` НЕТ
 *   `bootstrap/providers.php` намеренно не бутсит модульные провайдеры, а
 *   `checkin:middleware` регистрируется только из провайдера модуля. Вместо
 *   висячей ссылки на middleware класс указан прямо в маршруте
 *   (`app/Modules/Checkin/routes/api.php`) — так проверяемо и не зависит от
 *   того, какой из провайдеров когда-нибудь поднимется.
 *
 * ПОРЯДОК В МАРШРУТЕ ВАЖЕН: этот middleware должен идти ПЕРЕД
 * `throttle:checkin-scan`, потому что лимитер берёт идентификатор устройства из
 * атрибута, который он выставляет (`AuthenticateChecker::ATTRIBUTE`).
 */
final class AuthenticateChecker
{
    /** Ключ в `$request->attributes`: экземпляр `CheckinDevice`. */
    public const ATTRIBUTE = 'checkinDevice';

    public function __construct(
        private readonly OrganizationContext $context
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            throw AuthError::unauthenticated('A checker device token is required.');
        }

        // Поиск по хешу, а не по токену: сырой токен в базе не хранится (см.
        // докблок `CheckinDevice`). sha256 — потому что `device_token_hash`
        // CHAR(64) хранит именно его hex-дайджест.
        $device = CheckinDevice::query()
            ->where('device_token_hash', $this->hash($token))
            ->first();

        if ($device === null) {
            throw new AuthError(
                'Unknown device token.',
                'INVALID_DEVICE_TOKEN',
                401
            );
        }

        if ($device->status !== DevicePolicy::STATUS_ACTIVE) {
            throw new AuthError(
                'This device is not active.',
                'DEVICE_NOT_ACTIVE',
                401,
                ['status' => $device->status]
            );
        }

        $this->context->set((string) $device->organization_id);
        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    /**
     * Устройство из запроса. Контроллеры берут его отсюда — не раньше и не
     * позже: до middleware идентификации нет, после —DeviceTokenIssuer больше
     * ничего не добавляет.
     */
    public static function device(Request $request): CheckinDevice
    {
        $device = $request->attributes->get(self::ATTRIBUTE);

        if (! $device instanceof CheckinDevice) {
            // Достижимо только при незакрытом маршрутом вызове контроллера.
            throw AuthError::unauthenticated('A checker device token is required.');
        }

        return $device;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        $prefix = 'Bearer ';

        if (! str_starts_with($header, $prefix)) {
            return null;
        }

        $token = trim(substr($header, strlen($prefix)));

        return $token === '' ? null : $token;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
