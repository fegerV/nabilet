<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Заказ — ровно форма схемы `Order` контракта (`nabilet_core_spec/openapi.yaml`).
 *
 * ЗАЧЕМ ЭТОТ КЛАСС ПЕРЕПИСАН
 *   До правки в проекте жили ТРИ несогласованные формы одного заказа:
 *     1. схема `Order` контракта (`totals` объектом, `customer_email` плоско,
 *        `items[].event_title`);
 *     2. этот ресурс (`total_amount`, `customer{email,phone,first_name,last_name}`,
 *        `items_count`, `expires_at`, `paid_amount`);
 *     3. то, что реально уходило в ответ, — КОЛОНКИ таблицы: `index()`, `show()`
 *        и `mine()` сериализовали модель `Order` напрямую, без ресурса.
 *
 *   Форма №2 была не «почти верной», а неверной по составу: она ссылалась на
 *   пять колонок, которых в `orders` НЕТ (`customer_first_name`,
 *   `customer_last_name`, `items_count`, `expires_at`, `paid_amount`) — реальны
 *   `customer_name` (сплит `011`) и `paid_at`. Форма №3 утекала наружу
 *   внутренними BIGINT (`user_id`, `organization_id`, `promo_code_id`,
 *   `cart_id`, `session_id`, `event_id`), что запрещено `DATABASE.md` §9.
 *
 *   Наблюдаемое следствие формы №2: админский список заказов читал
 *   `o.customer.email`, получал `undefined` и печатал «—» в колонке
 *   «Покупатель» для КАЖДОЙ строки. Ошибка была не в вёрстке — в форме ответа.
 *
 * ЧТО ИЗМЕНИЛОСЬ ДЛЯ КЛИЕНТОВ
 *   `id` — это `public_id` (строка), а не BIGINT; суммы переехали в `totals`;
 *   покупатель — тремя плоскими полями (`customer_email` / `customer_name` /
 *   `customer_phone`), потому что в таблице хранится ОДНО `customer_name`, а не
 *   имя и фамилия отдельно; позиции — `event_title` / `session_title` /
 *   `venue_title` / `seat_snapshot` (см. `OrderItemResource`).
 *
 * `customer_name` добавлен в контракт этой же правкой: колонка реальная и
 * нужна админке, в схеме её просто не было.
 *
 * `payments` и `organization` из ответа убраны: их нет в схеме `Order`, а
 * `payments` встраивается в заказ только у `Embed` — там он и не нужен, платежи
 * клиент читает своим ресурсом (`PaymentResource`) через `GET /payments`.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'totals' => [
                'subtotal_amount' => (int) $this->subtotal_amount,
                'discount_amount' => (int) $this->discount_amount,
                // Списано бонусами — отдельным членом, а не «магической скидкой»:
                // покупатель должен видеть, сколько заказа закрыто лояльностью,
                // а разбор заказа — отличать код от бонусов (см. `OrderTotals`).
                'bonus_amount' => (int) $this->bonus_amount,
                'fee_amount' => (int) $this->fee_amount,
                'total_amount' => (int) $this->total_amount,
                'currency' => (string) ($this->currency ?? 'RUB'),
            ],
            'customer_email' => $this->customer_email,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            // Коллекция отдаётся только когда связь действительно загружена:
            // `whenLoaded` возвращает MissingValue, и Laravel выкидывает ключ
            // целиком, а не печатает `null`. Список заказов связь грузит
            // (`OrderRepository::paginateAll`), поэтому там позиции есть.
            'items' => $this->whenLoaded(
                'items',
                fn (): mixed => OrderItemResource::collection($this->items),
            ),
        ];
    }
}
