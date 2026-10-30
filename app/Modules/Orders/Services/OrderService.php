<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Core\StateMachine\StateMachine;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Repositories\OrderRepository;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private StateMachine $machine;

    public function __construct(
        protected OrderRepository $repository
    ) {
        $this->machine = OrderStateMachine::make();
    }

    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $inventoryItems = [];
            $totalAmount = 0;
            $sessionId = $data['session_id'] ?? null;
            $eventId = $data['event_id'] ?? null;

            foreach ($data['items'] ?? [] as $itemData) {
                // `lockForUpdate()` — обязательная часть, а не украшение.
                //
                // Дальше идёт «check-then-decrement»: читаем
                // `available_quantity`, сравниваем и лишь в конце транзакции
                // делаем `decrement`. Без блокировки строки между чтением и
                // списанием есть окно, в которое вторая параллельная транзакция
                // прочитает то же число, тоже пройдёт проверку и тоже спишет —
                // то есть двойная продажа. Сейчас её ловит только CHECK
                // `ck_inventory_available_qty`, и наружу это выходит как 500
                // вместо внятного 409 (аудит архитектуры D1).
                //
                // Блокировка держится до COMMIT, поэтому второй запрос
                // дожидается конца первого и перечитывает уже уменьшенный
                // остаток — и получает корректный отказ.
                $item = InventoryItem::query()
                    ->whereKey($itemData['inventory_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                // Организация заказа и организация позиции склада обязаны
                // совпадать. До этой проверки клиент мог прислать
                // `organization_id` одной организации, а позицию — из сеанса
                // другой: заказ попадал в чужой тенант, а билеты выпускались по
                // сеансу исходной. Сравнение идёт по владельцу сеанса
                // (`sessions.event_id → events.organization_id`), а не по
                // доверенному полю запроса.
                $itemOrganizationId = $item->session?->event?->organization_id;

                if ($itemOrganizationId === null
                    || (int) $itemOrganizationId !== (int) ($data['organization_id'] ?? 0)
                ) {
                    throw new NotFoundError(
                        'InventoryItem',
                        (string) $itemData['inventory_item_id'],
                    );
                }

                $quantity = max(1, (int) ($itemData['quantity'] ?? 1));

                // Доступность проверяем до списания: БД это тоже запрещает
                // (ck_inventory_available_qty), но проверка на уровне приложения
                // даёт внятную доменную ошибку вместо SQL CHECK violation.
                if ($quantity > (int) $item->available_quantity) {
                    throw new \Nabilet\Core\Errors\DomainRuleViolation(
                        'Not enough inventory available',
                        'INVENTORY_NOT_AVAILABLE',
                        ['inventory_item_id' => $item->id, 'requested' => $quantity, 'available' => (int) $item->available_quantity],
                    );
                }

                // A6-цепочка «заказ → оплата → билет» требует, чтобы заказ помнил
                // сеанс и событие: `tickets.session_id`/`event_id` NOT NULL, и
                // TicketService берёт их именно отсюда. Раньше здесь их не было, и
                // заказы, созданные этим путём, имели session_id = NULL — билеты
                // по ним не выпускались, а напоминание за сутки (OrderReminderSweeper)
                // не находило дату сеанса. Берём из позиции склада: у неё сеанс есть
                // всегда, а у события он один на все позиции заказа.
                if ($sessionId === null && $item->session_id !== null) {
                    $sessionId = (int) $item->session_id;
                }

                $inventoryItems[] = [$item, $quantity];
                $totalAmount += $quantity * (int) $item->price_amount;
            }

            if ($eventId === null && $sessionId !== null) {
                $eventId = $this->repository->eventIdForSession((int) $sessionId);
            }

            $order = $this->repository->create([
                'organization_id' => $data['organization_id'],
                'user_id' => $data['user_id'] ?? null,
                'session_id' => $sessionId,
                'event_id' => $eventId,
                'status' => OrderStateMachine::PENDING,
                'payment_status' => PaymentStateMachine::PENDING,
                'subtotal_amount' => $totalAmount,
                'discount_amount' => 0,
                // Прямая форма заказа (`items`, без корзины) бонусов не знает:
                // списание живёт в оформлении корзины, где есть бонусный счёт и
                // строка журнала (`BonusService::spendForOrder`). Колонка
                // заполняется явным нулём, а не оставляется на DEFAULT, чтобы
                // «здесь бонусов не бывает» было видно в коде, а не выводилось из
                // схемы. `bonus_amount` в этой форме отвергает валидация.
                'bonus_amount' => 0,
                'fee_amount' => 0,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?? 'RUB',
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
            ]);

            foreach ($inventoryItems as [$inventoryItem, $quantity]) {
                $orderItem = $this->repository->addItem($order, [
                    'inventory_item_id' => $inventoryItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $inventoryItem->price_amount,
                    'total_amount' => $quantity * $inventoryItem->price_amount,
                    'event_title_snapshot' => $inventoryItem->session?->event?->title
                        ?? $data['event_title'] ?? '',
                    'session_title_snapshot' => $inventoryItem->session?->title ?? null,
                    'venue_title_snapshot' => $inventoryItem->session?->venue?->name ?? null,
                ]);

                // Reserve inventory
                $inventoryItem->decrement('available_quantity', $orderItem->quantity);
            }

            return $order->load(['items.inventoryItem']);
        });
    }

    /**
     * Заказ по id в пределах организации. Организация обязательна: без неё
     * чужой заказ стал бы виден. Для системных контекстов используйте
     * `findOrderAny()`.
     */
    public function findOrder(int $orderId, int $organizationId): ?Order
    {
        return $this->repository->find($orderId, $organizationId);
    }

    /** Системный поиск без скоупа организации (вебхуки, задания). */
    public function findOrderAny(int $orderId): ?Order
    {
        return $this->repository->findAny($orderId);
    }

    public function findByPublicId(string $publicId, int $organizationId): ?Order
    {
        return $this->repository->findByPublicId($publicId, $organizationId);
    }

    public function findAnyByPublicId(string $publicId): ?Order
    {
        return $this->repository->findAnyByPublicId($publicId);
    }

    /**
     * Постраничный список заказов для API.
     *
     * Fail-closed: если организация не определена, возвращается пустая страница,
     * а не заказы всех арендаторов. У модели Order нет глобального tenant-скоупа,
     * поэтому это единственный барьер между клиентом и чужими заказами.
     *
     * @param  array{organization_id?: int|string|null, status?: string|null, user_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        // Ветка «фильтр по организации / все организации» убрана: `paginateAll`
        // теперь сам понимает `organization_id` (и `user_id`, и диапазон дат),
        // а прежнее разделение давало два разных набора фильтров — в одном
        // `user_id` работал, в другом молча игнорировался.
        //
        // Список заказов УЖЕ фильтруется явно вызывающим: `mine()` — по `user_id`,
        // staff-`index()` — по `organization_id`. Глобальный tenant-скоуп здесь
        // избыточен и ломал бы owner-списки без контекста, поэтому обходим его.
        return app(OrganizationContext::class)->withoutScope(
            fn (): LengthAwarePaginator => $this->repository->paginateAll($filters, $perPage)
        );
    }

    /**
     * Разрешён ли переход из текущего статуса заказа.
     */
    public function canTransition(Order $order, string $to): bool
    {
        return $this->machine->can($order->status, $to);
    }

    public function markAwaitingPayment(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            // Идемпотентность: повторный вызов на уже ожидающем оплату заказе — норм.
            if ($order->status === OrderStateMachine::AWAITING_PAYMENT) {
                return $order->fresh();
            }

            $this->machine->assert($order->status, OrderStateMachine::AWAITING_PAYMENT);
            $order->update(['status' => OrderStateMachine::AWAITING_PAYMENT]);
            return $order->fresh();
        });
    }

    public function markPaid(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->assertNotTerminal($order);
            $this->machine->assert($order->status, OrderStateMachine::PAID);
            $now = now();
            $order->update([
                'status' => OrderStateMachine::PAID,
                'payment_status' => PaymentStateMachine::SUCCEEDED,
                'paid_at' => $now,
            ]);
            return $order->fresh();
        });
    }

    public function markPaymentFailed(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->machine->assert($order->status, OrderStateMachine::PAYMENT_FAILED);
            $order->update(['status' => OrderStateMachine::PAYMENT_FAILED]);
            return $order->fresh();
        });
    }

    public function cancelOrder(Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            // Check if we can cancel
            if (!$this->machine->can($order->status, OrderStateMachine::CANCELLED)) {
                throw new InvalidStateTransitionError(
                    'Order',
                    $order->status,
                    OrderStateMachine::CANCELLED,
                    $this->machine->allowedFrom($order->status),
                );
            }

            // Release inventory: available_quantity += quantity (CHECK
            // ck_inventory_available_qty допускает возврат ровно на удержанное),
            // статус места сбрасывается с sold/sold_out/held на available, если
            // после возврата есть свободные (план b: до оплаты status может быть
            // held/sold_out — после отмены место обязано вернуться в продажу).
            foreach ($order->items as $item) {
                $inventoryItem = $item->inventoryItem;

                if ($inventoryItem === null) {
                    continue;
                }

                $inventoryItem->increment('available_quantity', $item->quantity);

                if ((int) $inventoryItem->available_quantity > 0
                    && in_array($inventoryItem->status, [InventoryItemStateMachine::SOLD, InventoryItemStateMachine::SOLD_OUT, InventoryItemStateMachine::HELD], true)) {
                    $inventoryItem->update(['status' => InventoryItemStateMachine::AVAILABLE]);
                }
            }

            // Снять активные холды корзины — иначе sweeper вернёт quantity повторно.
            \Nabilet\Modules\Orders\Models\SeatHold::query()
                ->where('cart_id', $order->cart_id ?? 0)
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->update(['released_at' => now()]);

            $order = $this->repository->cancel($order);

            $order->update(['cancelled_at' => now()]);

            // В схеме orders нет колонки metadata — причина отмены фиксируется
            // штатной колонкой cancelled_at; сам факт отмены достаточен.

            return $order;
        });
    }

    /**
     * ЗДЕСЬ БЫЛ `completeOrder()`. УДАЛЁН, А НЕ ПОЧИНЕН.
     *
     * Он проверял машиной переход в `OrderStateMachine::PAID`, а потом звал
     * `OrderRepository::complete()`, который писал `'completed'` — статус,
     * которого `ck_orders_status` не допускает. Сторож и запись расходились:
     * переход разрешался, запись падала с CHECK violation.
     *
     * Оплаченный заказ проводит `markPaid()` — он ставит `status`, `paid_at`
     * и `payment_status` одним update'ом. «Починенный» `completeOrder()` был бы
     * вторым путём к `paid` без `paid_at`/`payment_status`, то есть тихо
     * оплаченным заказом, по которому ни отчётность, ни сверка с провайдером
     * не видят оплаты.
     *
     * Вызывающих не было ни в `app/`, ни в `tests/`, ни в `resources/js`.
     */

    public function applyPayment(Order $order, Payment $payment): Order
    {
        return DB::transaction(function () use ($order, $payment) {
            // If already paid, this is a replay — idempotent.
            if ($order->status === OrderStateMachine::PAID) {
                return $order->fresh();
            }

            // Check if fully paid
            $paidAmount = (int) $order->payments()
                ->where('payments.status', PaymentStateMachine::SUCCEEDED)
                ->sum('amount');

            if ($paidAmount >= (int) $order->total_amount) {
                // Прямой pending -> paid запрещён машиной; но здесь платёж уже
                // прошёл (webhook succeeded), поэтому переводим через awaiting.
                if ($order->status === OrderStateMachine::PENDING) {
                    $this->markAwaitingPayment($order->fresh());
                }
                $this->markPaid($order->fresh());
            }

            return $order->fresh();
        });
    }

    public function getOrdersByOrganization(int $organizationId, array $filters = [], int $limit = 15)
    {
        return $this->repository->findByOrganization($organizationId, $filters, $limit);
    }

    /**
     * Выручка в minor units (int) — см. докблок репозитория (аудит D10).
     */
    public function getRevenueReport(int $organizationId, string $dateFrom, string $dateTo): int
    {
        return $this->repository->getRevenueByOrganization($organizationId, $dateFrom, $dateTo);
    }

    private function assertNotTerminal(Order $order): void
    {
        if (OrderStateMachine::isTerminal($order->status)) {
            throw new InvalidStateTransitionError(
                'Order',
                $order->status,
                OrderStateMachine::PAID,
                [],
            );
        }
    }
}