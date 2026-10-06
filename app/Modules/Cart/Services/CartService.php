<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Cart\Models\CartItem;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Cart domain service — владение корзиной и её жизненный цикл.
 *
 * РАЗДЕЛЕНИЕ (P2). Класс был одним файлом на 627 строк, совмещавшим три
 * обязанности с разными причинами изменения:
 *  - владение корзиной и её срок жизни — здесь;
 *  - позиции, списание/возврат инвентаря и холды — `CartItemService`;
 *  - превращение корзины в заказ — `CartCheckoutService`.
 *
 * Разделение сделано не ради строк: совмещение давало конкретный дефект.
 * `CartCheckoutService::checkout()` держал очистку истёкшей корзины внутри той
 * же транзакции, что и `throw`, поэтому откат отменял возврат инвентаря —
 * места не возвращались в продажу никогда (см. комментарий в `checkout()`).
 *
 * Ошибки поднимаются как подклассы `AppError`, а не `\RuntimeException`. Это не
 * стилистика: клиент ветвится по `error.code` и не должен разбирать
 * `error.message` (см. `AppError`), поэтому голый `\RuntimeException` не оставляет
 * вызывающему ничего машиночитаемого. Контроллеру нечего ловить — исключение
 * доходит до `ApiExceptionRenderer`, который рисует конверт §66.
 *
 * Коды ответов следуют `nabilet_core_spec/openapi.yaml`:
 *   POST /api/v1/carts/{cart}/items  →  '409': Inventory conflict,  '422': ValidationError
 *
 * Истёкшая корзина и распроданное место — оба *ожидаемые* исходы при
 * конкуренции, а не баги, отсюда `ConflictError` (409), ровно то, для чего этот
 * класс себя документирует.
 */
class CartService
{
    /**
     * Получить или создать корзину покупателя для сеанса (D5).
     *
     * `$token` обязателен: корзина без владельца была бы достижима из любого
     * браузера, разделяющего сеанс — ровно тот дефект, ради которого вводили D5.
     */
    public function getOrCreateCart(string $sessionId, string $token): Cart
    {
        $cart = Cart::query()
            ->where('session_id', $sessionId)
            ->where('status', 'active')
            ->where('cart_token', $token)
            ->first();

        if (!$cart) {
            $session = Session::findOrFailBySessionId($sessionId);

            // Sales gate: корзину можно открыть только для сеанса, который
            // реально продаётся прямо сейчас — статус `on_sale` И внутри окна
            // продаж. Без этого места можно было увести из оборота (создав холд)
            // до старта продаж или после их закрытия, полностью обойдя контроль
            // организатора.
            if (! $session->isSellableAt()) {
                throw DomainRuleViolation::salesClosed(
                    (string) ($session->public_id ?? $session->id),
                    ['reason' => $session->salesBlockReason()]
                );
            }

            $cart = Cart::create([
                'session_id' => $sessionId,
                'cart_token' => $token,
                'user_id' => $session->user_id ?? null,
                'status' => 'active',
                // C1: единый источник истины для срока холда — конфиг
                // nabilet.checkout.hold_duration_minutes (дефолт 15). UI больше
                // не считает таймер локально: expires_at отдаётся сервером в
                // каждом ответе корзины.
                'expires_at' => CarbonImmutable::now()->addMinutes(self::holdDurationMinutes()),
            ]);
        }

        return $cart;
    }

    /**
     * C1: срок удержания в минутах из конфига; `HoldWindow` ограничивает TTL
     * диапазоном 300–1800 секунд, поэтому выход за диапазон нормализуется.
     *
     * `public static`, потому что это настройка, а не состояние: её читают и
     * `getOrCreateCart()` здесь, и `CartItemService::extendHold()`. Оставить
     * второй литерал «15» в другом файле означало бы разойтись при смене
     * настройки — тот же класс дефекта, что и с grace-окном.
     */
    public static function holdDurationMinutes(): int
    {
        $minutes = max(1, (int) config('nabilet.checkout.hold_duration_minutes', 15));

        return min(30, $minutes);
    }

    /**
     * Очистить все позиции корзины покупателя.
     *
     * ВНИМАНИЕ: у метода сейчас НЕТ вызывающего — маршрута `DELETE /api/v1/cart`
     * не существует, и ни один контроллер его не зовёт (проверено по всему
     * дереву, включая витрину). Либо под него нужен эндпоинт, либо его следует
     * удалить: неработающий публичный метод создаёт ложное впечатление, что
     * очистка корзины поддерживается. Помечено, а не удалено молча, потому что
     * решение продуктовое.
     *
     * Без токена — no-op, чтобы случайный вызов не снёс активные корзины всех
     * покупателей сеанса.
     */
    public function clearCart(string $sessionId, ?string $token = null): bool
    {
        $token = CartToken::normalize($token);

        if ($token === null) {
            return false;
        }

        return DB::transaction(function () use ($sessionId, $token) {
            $cart = Cart::query()
                ->where('session_id', $sessionId)
                ->where('status', 'active')
                ->where('cart_token', $token)
                ->first();

            if (!$cart) {
                return false;
            }

            CartItem::where('cart_id', $cart->id)->delete();

            $cart->update([
                'total_amount' => '0',
            ]);

            return true;
        });
    }
}
