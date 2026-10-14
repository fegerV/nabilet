<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Models\BonusAccount;
use Nabilet\Modules\Loyalty\Models\BonusTransaction;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Бонусный счёт: начисление, списание, компенсация и чтение баланса.
 *
 * ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ДВИГАЕТСЯ БАЛАНС
 *   `bonus_accounts.balance` меняется только отсюда, и меняется вместе со
 *   строкой журнала, в одной транзакции. Это то же решение, что у
 *   `OrderTotals` для сумм заказа: если бы баланс двигали из двух мест,
 *   расхождение «в журнале одно, на счёте другое» появилось бы тихо и всплыло
 *   бы при сверке. Три направления движения — `accrueForOrder()` (плюс),
 *   `spendForOrder()` (минус) и `refundForOrder()` (плюс) — и все три здесь.
 *
 * ПОЧЕМУ ИДЕМПОТЕНТНОСТЬ В СХЕМЕ, А НЕ ПРОВЕРКОЙ ЗДЕСЬ
 *   «Уже начислено?» отдельным SELECT не работает: джоба повторилась, и между
 *   SELECT и INSERT влез второй воркер. Разрешение на начисление выдаёт
 *   уникальный ключ `uq_bonus_transactions_dedupe` — вставка строки журнала с
 *   `dedupe_key = accrual:{order_id}` либо проходит, либо падает конфликтом,
 *   который здесь и обрабатывается. Тот же приём, что у
 *   `FollowNotification::claim()`.
 *
 * ПОЧЕМУ БАЛАНС ДВИГАЕТСЯ `increment`/`decrement`, А НЕ `update`
 *   `update(['balance' => $read + $delta])` — это чтение и запись: два заказа
 *   одного покупателя, оплаченных одновременно, прочитали бы одно и то же
 *   значение и одно из начислений потерялось бы. `increment` отдаёт сложение
 *   базе, поэтому потерять его нельзя.
 *
 * СПИСАНИЕ — С УСЛОВИЕМ В САМОМ UPDATE
 *   `decrement` идёт с `where('balance', '>=', ...)`, и ноль затронутых строк
 *   означает «не хватило». Проверка баланса отдельным SELECT перед записью была
 *   бы той же гонкой, что и выше: два чекаута одного покупателя прочитали бы
 *   один баланс и оба решили, что бонусов хватает. Условие в UPDATE делает
 *   проверку и запись одним атомарным действием, а `ck_bonus_accounts_balance`
 *   остаётся последней линией обороны.
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
     * Сколько бонусов покупатель может списать в заказе с такой суммой.
     *
     * Решение «сколько можно» принимает `BonusPolicy` (30% от подытога, не
     * больше остатка после скидки, не больше баланса), а этот метод добавляет к
     * нему две вещи, которых политика знать не может: ЧЕЙ баланс читать и что
     * чтение должно быть под блокировкой.
     *
     * ЗАЧЕМ БЛОКИРОВКА НА ЧТЕНИИ
     *   Чекаут вызывает это, чтобы посчитать итог заказа, и записывает списание
     *   через несколько строк — в той же транзакции. `lockForUpdate()` держит
     *   строку счёта до её конца, поэтому второй одновременный чекаут того же
     *   покупателя ждёт и видит уже уменьшенный баланс. Без блокировки оба
     *   прочитали бы одно число, оба решили бы, что бонусов хватает, и один
     *   заказ ушёл бы в минус по балансу (CHECK поймал бы, но как ошибка
     *   покупателя на оплате, а не как отказ на оформлении).
     *
     * `null` в качестве покупателя — гость без аккаунта: списывать не с чего, и
     * это ноль, а не исключение. Ровно как в `accrueForOrder()`.
     */
    public function spendableFor(?int $userId, Money $subtotal, ?Money $discount = null): Money
    {
        if ($userId === null) {
            return Money::zero($subtotal->currency());
        }

        $account = BonusAccount::query()
            ->forUser($userId)
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            // Счёт заводится лениво (`forUserOrCreate`), и его отсутствие значит
            // «бонусов нет», а не «покупателя нет».
            return Money::zero($subtotal->currency());
        }

        return BonusPolicy::maxSpendable(
            $subtotal,
            Money::of((int) $account->balance, (string) ($account->currency ?: $subtotal->currency())),
            $discount,
        );
    }

    /**
     * Списать бонусы в заказе — единственный писатель со знаком «минус».
     *
     * ПОЧЕМУ СПИСАНИЕ, А НЕ «РЕЗЕРВ»
     *   План (`docs/LOYALTY-PLAN.md`) говорит «резерв до оплаты», и это верно по
     *   смыслу: бонусы удерживаются на момент чекаута, а не на момент оплаты.
     *   Но удержание сделано СТРОКОЙ ЖУРНАЛА, а не отдельным состоянием
     *   «зарезервировано». Причина денежная: баланс — то число, которое покупатель
     *   видит в шапке и на `/bonus`, и то, по которому считается «сколько ещё
     *   можно списать». Если бы удержание не уменьшало баланс, один и тот же
     *   баланс мог бы обеспечить списание в двух незакрытых заказах сразу, и
     *   второе списание ушло бы в минус. Отдельной «фиксации при `markPaid`»
     *   поэтому тоже нет: к моменту оплаты баланс уже уменьшен, и фиксировать
     *   нечего. Обратная сторона — неоплаченный заказ держит бонусы, как он
     *   держит места; освобождает их `refundForOrder()`.
     *
     * ПОЧЕМУ ЗДЕСЬ ЕЩЁ РАЗ ПРОВЕРЯЕТСЯ ЛИМИТ
     *   Чекаут уже спросил `spendableFor()` и отказал покупателю, если тот просил
     *   больше. Проверка повторяется, потому что этот метод — публичная точка
     *   входа: команда пересчёта или ручной вызов не должны получить возможность
     *   списать больше лимита в обход чекаута.
     *
     * @return BonusTransaction|null `null`, если списывать нечего (ноль или гость)
     * @throws DomainRuleViolation лимит превышен или баланса не хватает
     */
    public function spendForOrder(Order $order, Money $amount, Money $subtotal, ?Money $discount = null): ?BonusTransaction
    {
        if ($amount->isZero()) {
            // Ноль — не ошибка: покупатель мог не отметить галочку. Строки «−0» в
            // журнале быть не должно, как и «+0» при начислении.
            return null;
        }

        if ($amount->isNegative()) {
            throw new DomainRuleViolation(
                sprintf('A bonus deduction cannot be negative (%d).', $amount->minorUnits()),
                'INVALID_SPEND',
            );
        }

        $userId = $order->user_id;

        // Гость без аккаунта: списывать не с чего. Заказ уже посчитан без бонусов
        // (см. `spendableFor()`), поэтому это не ошибка, а согласованный ноль.
        if ($userId === null) {
            return null;
        }

        $cap = $this->spendableFor((int) $userId, $subtotal, $discount);

        if ($amount->greaterThan($cap)) {
            throw new DomainRuleViolation(
                sprintf(
                    'Cannot spend %d bonus minor units: the limit for this order is %d.',
                    $amount->minorUnits(),
                    $cap->minorUnits()
                ),
                'BONUS_LIMIT_EXCEEDED',
                [
                    'requested' => $amount->minorUnits(),
                    'available' => $cap->minorUnits(),
                    'subtotal_amount' => $subtotal->minorUnits(),
                ],
            );
        }

        return DB::transaction(function () use ($order, $userId, $amount): ?BonusTransaction {
            // Сначала строка журнала — она же разрешение на списание. Порядок
            // важен по той же причине, что и при начислении: повтор (обсервер
            // сработал дважды, транзакция переиграна) не должен списать дважды.
            try {
                $transaction = BonusTransaction::create([
                    'user_id' => $userId,
                    'order_id' => $order->getKey(),
                    'delta' => -$amount->minorUnits(),
                    'reason' => BonusReason::SPEND,
                    'dedupe_key' => self::dedupeKeyForSpend($order),
                ]);
            } catch (UniqueConstraintViolationException) {
                // По этому заказу уже списывали. Ни строки, ни баланса.
                return null;
            }

            $affected = BonusAccount::query()
                ->forUser($userId)
                ->where('balance', '>=', $amount->minorUnits())
                ->decrement('balance', $amount->minorUnits());

            if ($affected === 0) {
                // Условие в UPDATE не выполнилось — баланс меньше списания.
                // Строка журнала уже вставлена, поэтому транзакция обязана
                // откатиться целиком: иначе «в журнале −300, на счёте 0».
                // Исключение из `DB::transaction` откатывает именно это.
                throw new DomainRuleViolation(
                    sprintf(
                        'Cannot spend %d bonus minor units: the balance is smaller.',
                        $amount->minorUnits()
                    ),
                    'INSUFFICIENT_BONUS_BALANCE',
                    ['requested' => $amount->minorUnits()],
                );
            }

            return $transaction;
        });
    }

    /**
     * Вернуть списанные бонусы: отмена, истечение срока оплаты или возврат заказа.
     *
     * ОДИН МЕТОД НА ТРИ СЛУЧАЯ — И ЭТО НАМЕРЕННО
     *   Со стороны журнала это одно движение: причина `refund` по словарю
     *   (`019_bonus_ledger.sql`) закрывает и возврат, и отмену. Различать их
     *   строкой журнала незачем: заказ уже хранит свой финальный статус, и
     *   вопрос «почему вернули» отвечает он, а не дубль причины.
     *
     * ИДЕМПОТЕНТНОСТЬ ПО `release:{order_id}`
     *   Возврат может быть инициирован дважды (обсервер сработал на переходе в
     *   `cancelled`, затем админский сценарий выставил `expired`), и второй раз
     *   возвращать нельзя. Ключ `refund:{order_id}` — та же защита, что
     *   `accrual:{order_id}` у начисления. Обратите внимание: у начисления и
     *   возврата РАЗНЫЕ ключи, поэтому возврат не конфликтует с начислением по
     *   тому же заказу.
     *
     * @return BonusTransaction|null `null`, если возвращать нечего или уже вернули
     */
    public function refundForOrder(Order $order): ?BonusTransaction
    {
        $spent = (int) ($order->bonus_amount ?? 0);

        // Списывали не бонусами — возвращать нечего. Это самый частый случай:
        // подавляющее большинство заказов оформлено без бонусов.
        if ($spent <= 0) {
            return null;
        }

        $userId = $order->user_id;

        if ($userId === null) {
            // Заказ без владельца бонусов не списывал (см. `spendForOrder`), и
            // `bonus_amount > 0` у него означал бы расхождение данных, а не
            // покупателя, которому надо вернуть.
            return null;
        }

        $currency = (string) ($order->currency ?: 'RUB');

        return DB::transaction(function () use ($order, $userId, $spent, $currency): ?BonusTransaction {
            try {
                $transaction = BonusTransaction::create([
                    'user_id' => $userId,
                    'order_id' => $order->getKey(),
                    'delta' => $spent,
                    'reason' => BonusReason::REFUND,
                    'dedupe_key' => self::dedupeKeyForRefund($order),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Уже возвращали по этому заказу.
                return null;
            }

            $account = BonusAccount::forUserOrCreate($userId, $currency);

            BonusAccount::query()
                ->whereKey($account->getKey())
                ->increment('balance', $spent);

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

    /**
     * Ключ идемпотентности списания по заказу.
     *
     * Отдельный префикс, а не тот же `accrual`: у одного заказа законно есть и
     * начисление, и списание, и возврат, и общий ключ столкнул бы их между собой
     * на `uq_bonus_transactions_dedupe` — то есть списание отменило бы начисление.
     */
    public static function dedupeKeyForSpend(Order $order): string
    {
        return 'spend:' . $order->getKey();
    }

    /** Ключ идемпотентности возврата по заказу. См. `dedupeKeyForSpend()`. */
    public static function dedupeKeyForRefund(Order $order): string
    {
        return 'refund:' . $order->getKey();
    }
}
