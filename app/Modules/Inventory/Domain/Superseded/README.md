# Superseded: домен инвентаря и удержаний

**Эти классы не участвуют в боевом пути.** Каталог — надгробие, не библиотека.

## Что здесь

| Класс | Что описывал |
|---|---|
| `HoldWindow` | срок удержания + grace-окно как одно окно |
| `InventoryStock` | резервирование единиц с инвариантами |
| `ReservationPolicy` | правила «сколько можно взять» + анти-скальпинг лимит |
| `SeatHold` | доменный VO холда (**не** Eloquent-модель `Orders\Models\SeatHold`) |

## Почему здесь

Ни один не инстанцируется в `app/`. `SeatHold` из этого каталога использовался
только соседями по кластеру и тестами. `ReservationPolicy` — только
`OrderPlacement` (см. `../Orders/Domain/Superseded/README.md`), который сам
не создаётся нигде.

## Где на самом деле живут эти правила

| Правило | Фактический источник |
|---|---|
| Срок удержания | `nabilet.checkout.hold_duration_minutes` (`CHECKOUT_HOLD_DURATION`) → `CartService::holdDurationMinutes()` → колонка `carts.expires_at` |
| Grace перед снятием | `nabilet.checkout.hold_grace_minutes` (`CHECKOUT_HOLD_GRACE_MINUTES`) → `HoldSweeper`, `Orders\Models\SeatHold::graceMinutes()`, `PaymentService` |
| Лимит единиц на заказ | `nabilet.checkout.max_items_per_order` (`CHECKOUT_MAX_ITEMS`) → `CartController::addItem()`; витрина получает число в `meta.max_tickets_per_order` |
| Атомарное списание остатка | `InventoryItemRepository` (decrement/increment) |
| Возврат просроченного | `Inventory\Services\HoldSweeper` |

**Осторожно с числами.** `HoldWindow` заявляет TTL 600 с и grace 30 с — это
**не** боевые значения: фактически 15 минут (`CHECKOUT_HOLD_DURATION`) и
5 минут (`CHECKOUT_HOLD_GRACE_MINUTES`). Именно на этом расхождении один раз
уже потерялся эффект от правки настройки.

До включения в боевой путь **правка любого файла здесь не меняет поведение
системы**.
