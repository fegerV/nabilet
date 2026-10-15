<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Позиция заказа — форма схемы `OrderItem` контракта.
 *
 * ЗАЧЕМ ПЕРЕПИСАН
 *   Прежняя редакция не соответствовала НИ таблице, НИ контракту: отдавала
 *   `id => $this->public_id` (у `order_items` нет `public_id` — выходил `null`),
 *   `total_price` (колонка называется `total_amount`) и вложенный
 *   `inventory_item`, дёргавший связь `inventoryItem` ленивой загрузкой. То есть
 *   каждая позиция заказа была набором `null`, а при отключённой ленивой
 *   загрузке — ещё и исключением. Не замечалось потому, что ресурс использовался
 *   только через `OrderResource` у `Embed`, и формы никто не проверял.
 *
 * СУФФИКС `_snapshot` СОЗНАТЕЛЬНО СНЯТ
 *   Колонки называются `event_title_snapshot` / `session_title_snapshot` /
 *   `venue_title_snapshot` — снимок названия НА МОМЕНТ покупки. Контракт
 *   (`OrderItem.event_title`) этот суффикс не несёт, и `verify-contract-schema.php`
 *   держит расхождение в `ACCEPTED_DRIFT` с пояснением. Здесь суффикс снимается,
 *   чтобы клиент не читал `event_title` из одного места, а `event_title_snapshot`
 *   из другого.
 *
 * `id` — ВНУТРЕННИЙ
 *   У `order_items` нет `public_id`, и контракт объявляет `OrderItem.id` строкой.
 *   Отдаётся `(string) $this->id`. Это зафиксировано как известное исключение:
 *   `verify-contract-schema.php` печатает по `OrderItem` NOTE «contract exposes
 *   `id` on a table with no public_id» — как и по `CartItem`, `UserSession`,
 *   `UserRole` и двум таблицам переводов. Добавление `public_id` в `order_items`
 *   — отдельная работа со сменой схемы, а не побочный эффект правки формы.
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'quantity' => (int) $this->quantity,
            'unit_price' => (int) $this->unit_price,
            'discount_amount' => (int) $this->discount_amount,
            'fee_amount' => (int) $this->fee_amount,
            'total_amount' => (int) $this->total_amount,
            'event_title' => $this->event_title_snapshot,
            'session_title' => $this->session_title_snapshot,
            'venue_title' => $this->venue_title_snapshot,
            'seat_snapshot' => $this->seat_snapshot_json,
        ];
    }
}
