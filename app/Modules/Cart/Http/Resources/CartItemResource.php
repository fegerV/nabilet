<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Позиция корзины для витрины.
 *
 * Здесь было две ошибки, из-за которых `GET /api/v1/cart` не работал вообще:
 *
 *  1. `$this->inventoryItem->whenLoaded('seat', …)` — `whenLoaded()` объявлен у
 *     `JsonResource`, а `inventoryItem` это Eloquent-МОДЕЛЬ. Каждый непустой
 *     ответ падал с `BadMethodCallException: Call to undefined method
 *     Nabilet\Modules\Inventory\Models\InventoryItem::whenLoaded()` и клиент
 *     получал 500 `INTERNAL_ERROR`. Проверено на живом стенде (лог
 *     `storage/logs/laravel-*.log`, 20:33). Следствие: восстановление корзины
 *     после перезагрузки страницы (`fetchCart()` в `lib/inventory.ts`, который
 *     зовут и выбор мест, и шаг оформления) не работало никогда — оно молча
 *     ловило 500 и показывало «корзина пуста» поверх реально удержанных мест.
 *
 *  2. `'price' => $this->inventoryItem->price` — такого атрибута у модели нет
 *     (в `inventory_items` колонка называется `price_amount`), поэтому ключ
 *     всегда уходил `null`. Отдаём настоящее поле.
 *
 * `seat` присутствует ВСЕГДА (null для стоячих зон): клиент читает
 * `inventory_item.seat.number` без проверок, поэтому пропуск ключа сломал бы
 * разбор ответа.
 */
class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->inventoryItem;
        $seat = $item?->seat;

        return [
            // `cart_items.public_id` в схеме НЕТ (колонки: id, cart_id,
            // inventory_item_id, quantity, unit_price, total_price,
            // seat_snapshot_json, ...), поэтому `$this->public_id` уходил `null`
            // всегда. Отдаём настоящий id: именно его ждёт
            // `DELETE /cart/items/{id}` (`CartController::removeItem(int $itemId)`),
            // и без него снятие холда с витрины было невозможно — `cart.meta[id]`
            // получал `"null"`. Разграничение доступа не страдает: `removeItem()`
            // требует `X-Cart-Token` и проверяет принадлежность позиции корзине.
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'inventory_item' => [
                'id' => $item?->public_id,
                'type' => $item?->type,
                'price_amount' => $item?->price_amount,
                'seat' => $seat === null ? null : [
                    'id' => $seat->public_id,
                    'number' => $seat->number,
                    // `row` — это НОМЕР ряда (`hall_rows.number`), как и ожидает
                    // клиент: в `lib/inventory.ts` поле описано как `row?: number`.
                    'row' => $seat->row?->number,
                    'sector' => $seat->row?->sector?->name,
                ],
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
