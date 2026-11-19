<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Models\KioskDevice;
use Nabilet\Modules\Kiosk\Services\KioskTokenIssuer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Аутентификация информационного киоска по bearer-токену устройства.
 *
 * Полная копия fail-closed логики `AuthenticateChecker`, но с префиксом `nbks_`:
 *   - токен — capability, организации в контексте ещё нет, поиск в bypass;
 *   - разрешён только `active`, всё остальное — 401;
 *   - организация ставится ИЗ устройства, перекрывая поддомен white-label;
 *   - `sales_enabled` НЕ проверяется здесь: выключенные продажи всё равно
 *     должны получать heartbeat и конфиг (иначе киоск выглядит мертвым).
 *
 * Класс указывается прямо в маршруте: модульные middleware-алиасы не работают
 * надёжно, потому что `bootstrap/providers.php` не бутсит модули.
 */
final class AuthenticateKiosk
{
    public const ATTRIBUTE = 'kioskDevice';

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            throw AuthError::unauthenticated('A kiosk device token is required.');
        }

        $device = $this->context->withoutScope(
            fn (): ?KioskDevice => KioskDevice::query()
                ->where('kiosk_token_hash', KioskTokenIssuer::hash($token))
                ->first()
        );

        if ($device === null) {
            throw new AuthError('Unknown kiosk token.', 'INVALID_KIOSK_TOKEN', 401);
        }

        if ($device->status !== DevicePolicy::STATUS_ACTIVE) {
            throw new AuthError(
                'This kiosk is not active.',
                'KIOSK_NOT_ACTIVE',
                401,
                ['status' => $device->status],
            );
        }

        $this->context->set((string) $device->organization_id);
        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    public static function device(Request $request): KioskDevice
    {
        $device = $request->attributes->get(self::ATTRIBUTE);

        if (! $device instanceof KioskDevice) {
            throw AuthError::unauthenticated('A kiosk device token is required.');
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
}
