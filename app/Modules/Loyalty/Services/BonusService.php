<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Models\BonusAccount;
use Nabilet\Modules\Loyalty\Models\BonusTransaction;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Бонусный счёт: начисление и чтение баланса.
 *
 * ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ДВИГАЕТСЯ БАЛАНС
 *   `bonus_accounts.balance` меняется только отсюда, и меняется вместе со
 *   строкой журнала, в одной транзакции. Это то же решение, что у
 *   `OrderTotals` для сумм заказа: если бы баланс двигали из двух мест,
 *   расхождение «в журнале одно, на счёте другое» появилось бы тихо и всплыло
 *   бы при сверке.
 *
 * ПОЧЕМУ ИДЕМПОТЕНТНОСТЬ В СХЕМЕ, А НЕ ПРОВЕРКОЙ ЗДЕСЬ
 *   «Уже начислено?» отдельным SELECT не работает: джоба повторилась, и между
 *   SELECT и INSERT влез второй воркер. Разрешение на начисление выдаёт
 *   уникальный ключ `uq_bonus_transactions_dedupe` — вставка строки журнала с
 *   `dedupe_key = accrual:{order_id}` либо проходит, либо падает конфликтом,
 *   который здесь и обрабатывается. Тот же приём, что у
 *   `FollowNotification::claim()`.
 *
 * ПОЧЕМУ БАЛАНС ДВИГАЕТСЯ `increment`, А НЕ `update`
 *   `update(['balance' => $read + $accrual])` — это чтение и запись: два заказа
 *   одного покупателя, оплаченных одновременно, прочитали бы одно и то же
 *   значение и одно из начислений потерялось бы. `increment` отдаёт сложение
 *   базе, поэтому потерять его нельзя.
 */
class BonusService
{
    /**
     * Начислить кэшбэк за оплаченный заказ.
     *
     * `null` означает «движения нет» и покрывает три разных случая, каждый из
     * которых — не ошибка: заказ не оплачен, заказ не привязан к покупателю
     * (гость без аккаунта), кэшбэк нулевой, или начисление уже прошло.
     *
     * @return BonusTransaction|null строка журнала, если начисление состоялось
     */
    public function accrueForOrder(Order $order): ?BonusTransaction
    {
        // Кэшбэк — только за оплаченный заказ. Обсервер уже фильтрует по статусу,
        // но сервис — публичная точка входа: команда пересчёта или ручной вызов
        // не должны начислять бонусы за неоплаченное.
        if ($order->status !== OrderStateMachine::PAID) {
            return null;
        }

        // Гость без аккаунта: начислять некому. Это не ошибка — покупатель по
        // умолчанию гость, и заказ без `user_id` нормален для гостевого чекаута.
        $userId = $order->user_id;

        if ($userId === null) {
            return null;
        }

        $accrual = BonusPolicy::accrualFor(
            Money::of((int) $order->total_amount, (string) ($order->currency ?: 'RUB'))
        );

        // Нулевой заказ: строка «+0» в истории покупателя — это шум, а не факт.
        if ($accrual->isZero()) {
            return null;
        }

        return DB::transaction(function () use ($order, $userId, $accrual): ?BonusTransaction {
            // Сначала строка журнала — она же разрешение на начисление. Порядок
            // важен: если бы счёт заводился первым, повтор джобы создавал бы
            // пустой счёт заказу, за который бонусы уже начислены.
            try {
                $transaction = BonusTransaction::create([
                    'user_id' => $userId,
                    'order_id' => $order->getKey(),
                    'delta' => $accrual->minorUnits(),
                    'reason' => BonusReason::ACCRUAL,
                    'dedupe_key' => self::dedupeKeyForOrder($order),
                ]);
            } catch (UniqueConstraintViolationException) {
                // По этому заказу уже начисляли. Ни строки, ни баланса — и это
                // ровно то поведение, которого требует повтор джобы.
                return null;
            }

            $account = BonusAccount::forUserOrCreate($userId, $accrual->currency());

            BonusAccount::query()
                ->whereKey($account->getKey())
                ->increment('balance', $accrual->minorUnits());

            return $transaction;
        });
    }

    /**
     * Баланс покупателя. Нет счёта — ноль, а не исключение: счёт заводится
     * лениво, и «у покупателя ещё нет бонусов» — это ноль бонусов.
     */
    public function balanceFor(User $user): Money
    {
        $account = BonusAccount::query()->forUser($user->getKey())->first();

        return Money::of((int) ($account?->balance ?? 0), (string) ($account?->currency ?? 'RUB'));
    }

    /**
     * Ключ идемпотентности начисления по заказу.
     *
     * Формат `accrual:{order_id}` — часть контракта с базой: значение попадает в
     * `bonus_transactions.dedupe_key` под уникальный ключ, и разбор дубликата в
     * проде начинается с чтения этой строки. Поэтому ключ собирается здесь, а не
     * в каждом вызывающем.
     */
    public static function dedupeKeyForOrder(Order $order): string
    {
        return 'accrual:' . $order->getKey();
    }
}
