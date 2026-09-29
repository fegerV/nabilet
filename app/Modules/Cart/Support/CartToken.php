<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Идентификатор «покупателя-гостя» для корзины (фикс D5).
 *
 * Корзина ключуется парой (cart_token, session_id), а не только сеансом:
 * два параллельных покупателя одного сеанса больше не попадают в одну корзину.
 *
 * Токен приходит в заголовке `X-Cart-Token`. Если браузер его ещё не имеет,
 * клиент генерирует UUID и сохраняет у себя (localStorage) — сервер токен
 * не хранит в cookie, чтобы не зависеть от CSRF-инфраструктуры витрины.
 */
final class CartToken
{
    public const HEADER = 'X-Cart-Token';

    /**
     * Нормализовать входящий токен: обрезать, ограничить длину, принять только
     * безопасный алфавит (UUID/hex/base64url). Всё остальное — как будто токена нет.
     */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || strlen($raw) > 64) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_\-]+$/', $raw) === 1 ? $raw : null;
    }

    public static function fromRequest(Request $request): ?string
    {
        return self::normalize($request->header(self::HEADER));
    }

    /** Новый гостевой токен (для ответа, чтобы клиент мог его сохранить). */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }
}
