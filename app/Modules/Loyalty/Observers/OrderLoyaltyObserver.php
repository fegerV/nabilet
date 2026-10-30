<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Observers;

use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Services\LoyaltyLevelService;
use Nabilet\Modules\Orders\Models\Order;

/**
 * Пересчёт уровня «Клуба зрителей» при смене статуса заказа.
 *
 * ПОЧЕМУ ОБСЕРВЕР, А НЕ ВЫЗОВ ИЗ `OrderService::markPaid()`
 *   Заказ становится `paid` из нескольких мест (как описано в `OrderBonusObserver`):
 *   `markPaid()`, подтверждение платежа, админские сценарии, и возврат/отмена
 *   уводят статус обратно. Обсервер — единственная точка, где ловятся ВСЕ переходы,
 *   поэтому уровень поднимается на оплате и опускается на возврате без дублирования
 *   логики по местам вызова. Это третий наблюдатель на `Order` (первый —
 *   `OrderObserver` модуля Orders, второй — `OrderBonusObserver`); Laravel вызывает
 *   все три.
 *
 * ПОЧЕМУ `afterCommit()` НЕ НУЖЕН
 *   Пересчёт пишет только одну колонку на `users` и не двигает деньги. Если
 *   транзакция оплаты откатится, статус заказа не изменится, и обсервер не
 *   сработает — дополнительная развязка с транзакцией здесь только добавила бы
 *   задержку. Бонусы (деньги) поэтому висят на `afterCommit()`, а уровень — нет.
 */
final class OrderLoyaltyObserver
{
    public function __construct(private readonly LoyaltyLevelService $service)
    {
    }

    public function updated(Order $order): void
    {
        app(OrganizationContext::class)->withoutScope(function () use ($order): void {
            // Тот же статус записан повторно (идемпотентный `markPaid`) — пересчёт
            // бесполезен, а `wasChanged` ещё и защищает от лишней записи в базу.
            if (! $order->wasChanged('status')) {
                return;
            }

            $userId = $order->user_id;

            if ($userId === null) {
                return;
            }

            $user = User::find($userId);

            if ($user === null) {
                return;
            }

            $this->service->recompute($user);
        });
    }
}
