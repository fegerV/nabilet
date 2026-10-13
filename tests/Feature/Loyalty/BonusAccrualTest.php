<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Loyalty\Domain\BonusPolicy;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Jobs\AccrueOrderBonusJob;
use Nabilet\Modules\Loyalty\Models\BonusTransaction;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Клуб зрителей», фаза 2a: начисление кэшбэка за оплаченный заказ.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. МОМЕНТ НАЧИСЛЕНИЯ. Кэшбэк — это «спасибо за покупку», а не «спасибо за
 *      заказ»: начислять его на `pending` значило бы платить за брошенные
 *      корзины. Первый тест фиксирует, что джоба ставится ровно на переход в
 *      `paid` — и не ставится на `awaiting_payment`.
 *   2. ИДЕМПОТЕНТНОСТЬ. Джоба повторяется (`tries`/`backoff`), и повтор не имеет
 *      права начислить второй раз. Разрешение выдаёт `uq_bonus_transactions_dedupe`
 *      (`dedupe_key = accrual:{order_id}`), а не SELECT «уже начислено?» — между
 *      SELECT и INSERT влезает второй воркер. Тест `running_the_job_twice`
 *      проверяет именно это: две прогонки → одна строка журнала и один прирост
 *      баланса.
 *   3. СУММА И ЕДИНИЦЫ. `delta` — минорные единицы, `5%` от `total_amount`.
 *      Проверяется и «от итога, а не от subtotal»: заказ со скидкой разводит эти
 *      две величины, и тест ловит подмену одной на другую, которую иначе не
 *      видно (в `OrderService` они пока совпадают).
 *   4. НЕКОМУ НАЧИСЛЯТЬ — ЭТО НЕ ОШИБКА. Покупатель по умолчанию гость, и заказ
 *      без `user_id` нормален. Начисление молчит и не заводит счёт.
 *   5. ИНВАРИАНТЫ ДЕРЖИТ БАЗА. Словарь `reason`, неотрицательность баланса и
 *      уникальность ключа идемпотентности — это CHECK и UNIQUE в схеме, а не
 *      договорённость в коде. Приложение может ошибиться, сломаться или быть
 *      откатано — ограничения останутся.
 *
 * ПАЛИТРА СУММ ВЫБРАНА ТАК, ЧТОБЫ ОКРУГЛЕНИЕ БЫЛО ВИДНО: 12 345 минорных
 * единиц × 5% = 617,25 → 617 (половина вверх, `Money::percentage`). Ровная
 * сумма (5 000 000 → 250 000) проверяет арифметику, неровная — политику
 * округления на границе API, а не только в юнит-тесте `BonusPolicyTest`.
 */
class BonusAccrualTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── фикстуры ───────────────────────────────────────────────────────────

    private function seedUser(string $email = 'buyer@example.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Оплаченный заказ.
     *
     * Переход цепочкой `pending → awaiting_payment → paid`, а не сразу в `paid`:
     * прямого ребра `pending → paid` в `OrderStateMachine` нет, и «сократить»
     * здесь значило бы проверять путь, которого в проде не существует.
     */
    private function paidOrderFor(?int $userId, int $priceAmount = 5_000_000): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: $priceAmount,
        );

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);

        return $service->markPaid($order->fresh());
    }

    /**
     * Выполнить начисление так, как это сделал бы воркер очереди.
     *
     * Через джобу, а не через сервис: путь «оплата → обсервер → джоба → сервис»
     * и есть то, что проверяется. В тестах, где нужно вернуть строку журнала,
     * сервис вызывается напрямую — см. `accrual_returns_the_journal_row`.
     */
    private function runAccrualJob(Order $order): void
    {
        (new AccrueOrderBonusJob((int) $order->id))->handle(app(BonusService::class));
    }

    // ── 1. момент начисления ───────────────────────────────────────────────

    public function test_paying_an_order_queues_the_accrual_job(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        Bus::assertDispatched(AccrueOrderBonusJob::class, static fn (AccrueOrderBonusJob $job): bool =>
            $job->orderId === $order->id
            // `afterCommit()` — не деталь: джоба не должна уйти от откатившейся
            // транзакции оплаты, иначе покупатель получил бы бонусы за заказ,
            // которого нет.
            && $job->afterCommit === true
        );
    }

    public function test_awaiting_payment_does_not_queue_the_accrual_job(): void
    {
        Bus::fake();

        $userId = $this->seedUser();

        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $order = app(OrderService::class)->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        app(OrderService::class)->markAwaitingPayment($order);

        // Оплата ещё не пришла: кэшбэк за брошенную корзину — это подарок.
        Bus::assertNotDispatched(AccrueOrderBonusJob::class);
        $this->assertSame(0, DB::table('bonus_transactions')->count());
    }

    // ── 2. начисление ──────────────────────────────────────────────────────

    public function test_the_job_accrues_five_percent_and_mirrors_the_balance(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId, priceAmount: 12_345);

        $this->runAccrualJob($order);

        $row = DB::table('bonus_transactions')->where('order_id', $order->id)->first();

        $this->assertNotNull($row, 'оплаченный заказ обязан получить строку журнала');
        // 12 345 × 5% = 617,25 → 617 (половина вверх).
        $this->assertSame(617, (int) $row->delta);
        $this->assertSame(BonusReason::ACCRUAL, $row->reason);
        $this->assertSame($userId, (int) $row->user_id);
        // Ключ идемпотентности — часть контракта с базой, а не внутренняя деталь:
        // разбор дубликата в проде начинается с чтения этой строки.
        $this->assertSame('accrual:' . $order->id, $row->dedupe_key);
        // `public_id` — ULID base32: внутренний BIGINT не покидает API.
        $this->assertSame(26, mb_strlen((string) $row->public_id));

        $account = DB::table('bonus_accounts')->where('user_id', $userId)->first();
        $this->assertNotNull($account);
        $this->assertSame(617, (int) $account->balance);
        $this->assertSame('RUB', $account->currency);

        // Баланс — это кэш журнала, и они обязаны совпадать: расхождение
        // «в журнале одно, на счёте другое» всплыло бы только при сверке.
        $this->assertSame(
            (int) DB::table('bonus_transactions')->where('user_id', $userId)->sum('delta'),
            (int) $account->balance,
        );
    }

    public function test_accrual_returns_the_journal_row_and_skips_the_repeat(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        $service = app(BonusService::class);

        $first = $service->accrueForOrder($order->fresh());
        $this->assertInstanceOf(BonusTransaction::class, $first);

        // Второй вызов — тот же повтор джобы, и он обязан быть тихим: `null`,
        // а не исключение и не вторая строка.
        $second = $service->accrueForOrder($order->fresh());
        $this->assertNull($second, 'повтор начисления не должен проходить второй раз');

        $this->assertSame(1, DB::table('bonus_transactions')->count());
        $this->assertSame(250_000, (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance'));
    }

    public function test_running_the_job_twice_accrues_once(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        // Ровно то, что делает очередь при повторе после сетевого сбоя: та же
        // джоба, второй прогон. Баланс обязан вырасти один раз.
        $this->runAccrualJob($order);
        $this->runAccrualJob($order);

        $this->assertSame(1, DB::table('bonus_transactions')->count());
        $this->assertSame(250_000, (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance'));
    }

    public function test_the_accrual_is_five_percent_of_the_total_not_the_subtotal(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId, priceAmount: 10_000);

        // Скидка уменьшила итог: 10 000 → 4 000. Пока `OrderService` держит
        // subtotal и total равными, подмена одного другим невидима; этот тест
        // разводит их и ловит подмену.
        DB::table('orders')->where('id', $order->id)->update([
            'discount_amount' => 6_000,
            'total_amount' => 4_000,
        ]);

        $this->runAccrualJob($order->fresh());

        // 5% от 4 000 = 200, а не 5% от subtotal (500).
        $this->assertSame(
            200,
            (int) DB::table('bonus_transactions')->where('order_id', $order->id)->value('delta'),
        );
    }

    public function test_two_paid_orders_of_one_buyer_accumulate_on_one_account(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $first = $this->paidOrderFor($userId, priceAmount: 5_000_000);
        $second = $this->paidOrderFor($userId, priceAmount: 5_000_000);

        $this->runAccrualJob($first);
        $this->runAccrualJob($second);

        // Один счёт на человека (`UNIQUE (user_id)`), а не по строке на заказ.
        $this->assertSame(1, DB::table('bonus_accounts')->count());
        $this->assertSame(2, DB::table('bonus_transactions')->count());
        $this->assertSame(500_000, (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance'));
    }

    public function test_two_buyers_keep_separate_accounts(): void
    {
        Bus::fake();

        $first = $this->seedUser('buyer1@example.com');
        $second = $this->seedUser('buyer2@example.com');

        $this->runAccrualJob($this->paidOrderFor($first));
        $this->runAccrualJob($this->paidOrderFor($second));

        $this->assertSame(250_000, (int) DB::table('bonus_accounts')->where('user_id', $first)->value('balance'));
        $this->assertSame(250_000, (int) DB::table('bonus_accounts')->where('user_id', $second)->value('balance'));
    }

    // ── 3. некому начислять ────────────────────────────────────────────────

    public function test_a_guest_order_accrues_nothing(): void
    {
        Bus::fake();

        // Покупатель по умолчанию — гость, и заказ без `user_id` нормален:
        // начислять некому, и это не ошибка, а отсутствие движения.
        $order = $this->paidOrderFor(null);

        $this->assertNull(app(BonusService::class)->accrueForOrder($order->fresh()));

        $this->assertSame(0, DB::table('bonus_transactions')->count());
        $this->assertSame(0, DB::table('bonus_accounts')->count(), 'гостю не заводят пустой счёт');
    }

    public function test_an_unpaid_order_accrues_nothing(): void
    {
        Bus::fake();

        $userId = $this->seedUser();

        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $awaiting = $service->markAwaitingPayment($order);

        // Сервис — публичная точка входа: команда пересчёта или ручной вызов не
        // должны начислять за неоплаченное, даже если обсервер такой вызов не
        // сделал бы.
        $this->assertNull(app(BonusService::class)->accrueForOrder($awaiting->fresh()));

        $this->assertSame(0, DB::table('bonus_transactions')->count());
    }

    public function test_a_free_order_accrues_nothing(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId, priceAmount: 0);

        $this->assertNull(app(BonusService::class)->accrueForOrder($order->fresh()));

        // Строка «+0» в истории покупателя — шум, а не факт. Нулевое начисление
        // не должно ни писать журнал, ни заводить счёт.
        $this->assertSame(0, DB::table('bonus_transactions')->count());
        $this->assertSame(0, DB::table('bonus_accounts')->count());
    }

    // ── 4. инварианты держит база ──────────────────────────────────────────

    public function test_the_database_rejects_a_reason_outside_the_vocabulary(): void
    {
        $userId = $this->seedUser();

        // Словарь заперт `ck_bonus_transactions_reason`, и строка с чужой
        // причиной обязана упасть на вставке — даже если её пишет не модель, а
        // прямой SQL. Ошибка 3819, а не тихое «записалось непонятно что».
        $this->expectException(QueryException::class);
        // Сравниваем по имени ограничения, а не по полному тексту MySQL:
        // формулировка — дело сервера и меняется от версии, имя — наш контракт.
        $this->expectExceptionMessage('ck_bonus_transactions_reason');

        DB::table('bonus_transactions')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'order_id' => null,
            'delta' => 100,
            'reason' => 'cashback',
            'dedupe_key' => null,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_the_database_rejects_a_negative_balance(): void
    {
        $userId = $this->seedUser();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ck_bonus_accounts_balance');

        DB::table('bonus_accounts')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'balance' => -1,
            'currency' => 'RUB',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_a_second_account_for_the_same_user_is_impossible(): void
    {
        $userId = $this->seedUser();

        $row = [
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'balance' => 0,
            'currency' => 'RUB',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        DB::table('bonus_accounts')->insert($row);
        $row['public_id'] = (string) Str::ulid()->toBase32();

        // Два баланса на одного покупателя означали бы вопрос «какой из них
        // настоящий», который решается не в пользу покупателя.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('bonus_accounts')->insert($row);
    }

    public function test_the_dedupe_key_is_unique_across_rows(): void
    {
        $userId = $this->seedUser();

        $row = [
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'order_id' => null,
            'delta' => 100,
            'reason' => BonusReason::ACCRUAL,
            'dedupe_key' => 'accrual:1',
            'created_at' => now()->toDateTimeString(),
        ];

        DB::table('bonus_transactions')->insert($row);
        $row['public_id'] = (string) Str::ulid()->toBase32();

        // Инвариант идемпотентности держит база: приложение может ошибиться,
        // сломаться или быть откатано — ключ останется.
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('bonus_transactions')->insert($row);
    }

    public function test_rows_without_a_dedupe_key_do_not_collide(): void
    {
        $userId = $this->seedUser();

        $row = [
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'order_id' => null,
            'delta' => -100,
            'reason' => BonusReason::REFUND,
            'dedupe_key' => null,
            'created_at' => now()->toDateTimeString(),
        ];

        // Частичный возврат — законная ВТОРАЯ компенсация по тому же заказу, и
        // `UNIQUE (order_id, reason)` сделал бы её невозможной. NULL в UNIQUE
        // MySQL допускает сколько угодно, поэтому компенсации друг другу не
        // мешают.
        DB::table('bonus_transactions')->insert($row);
        $row['public_id'] = (string) Str::ulid()->toBase32();
        DB::table('bonus_transactions')->insert($row);

        $this->assertSame(2, DB::table('bonus_transactions')->count());
    }

    public function test_the_orders_check_still_guards_amounts_including_bonuses(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        // `ck_orders_amounts` переиздан вместе с `bonus_amount`: расширенный
        // CHECK обязан остаться рабочим. Отрицательный бонус — это не «странная
        // скидка», а дыра в деньгах, и база её не пропускает.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ck_orders_amounts');

        DB::table('orders')->where('id', $order->id)->update(['bonus_amount' => -1]);
    }

    public function test_deleting_the_buyer_takes_the_account_and_the_journal(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        $this->runAccrualJob($order);
        $this->assertSame(1, DB::table('bonus_accounts')->count());
        $this->assertSame(1, DB::table('bonus_transactions')->count());

        // Удаление покупателя уносит и счёт, и журнал (`ON DELETE CASCADE`):
        // счёт без владельца — это баланс, который некому показать.
        DB::table('users')->where('id', $userId)->delete();

        $this->assertSame(0, DB::table('bonus_accounts')->count());
        $this->assertSame(0, DB::table('bonus_transactions')->count());
    }

    public function test_deleting_the_order_keeps_the_bonus_history(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $order = $this->paidOrderFor($userId);

        $this->runAccrualJob($order);

        // `fk_bonus_transactions_order ON DELETE SET NULL`: заказ уходит, а
        // начисление остаётся. Иначе удаление заказа задним числом стёрло бы
        // историю бонусов покупателя — то есть объяснение его баланса.
        DB::table('orders')->where('id', $order->id)->delete();

        $row = DB::table('bonus_transactions')->where('user_id', $userId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->order_id);
        $this->assertSame(250_000, (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance'));
    }

    // ── 5. единицы отображения ─────────────────────────────────────────────

    public function test_the_display_rate_is_a_hundred_minor_units_per_bonus(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        // 250 000 минорных единиц кэшбэка — это 2 500 «бонусов» на витрине.
        $order = $this->paidOrderFor($userId, priceAmount: 5_000_000);

        $this->runAccrualJob($order);

        $balance = (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance');

        $this->assertSame(250_000, $balance);
        $this->assertSame(2_500, BonusPolicy::displayUnits($balance));
        // Обратный пересчёт — тот же курс: витрина и API обязаны делить на одно
        // и то же число, иначе баланс на экране и в письме разойдутся в 100 раз.
        $this->assertSame($balance, BonusPolicy::minorUnitsFor(BonusPolicy::displayUnits($balance)));
    }

    public function test_the_reason_vocabulary_matches_the_schema_check(): void
    {
        // Словарь `BonusReason` — это зеркало `ck_bonus_transactions_reason`.
        // Расхождение дало бы либо «приложение пишет то, что база не примет»
        // (ошибка 3819 в проде), либо «база принимает то, чего приложение не
        // знает». Проверяем каждое значение вставкой, а не сверкой списка.
        $userId = $this->seedUser();

        foreach (BonusReason::all() as $reason) {
            DB::table('bonus_transactions')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'user_id' => $userId,
                'order_id' => null,
                'delta' => 1,
                'reason' => $reason,
                'dedupe_key' => null,
                'created_at' => now()->toDateTimeString(),
            ]);
        }

        $this->assertSame(
            count(BonusReason::all()),
            DB::table('bonus_transactions')->where('user_id', $userId)->count(),
        );
    }

    public function test_a_paid_order_without_a_bonus_account_yet_has_a_zero_balance(): void
    {
        Bus::fake();

        $userId = $this->seedUser();

        // Счёт заводится лениво: «у покупателя ещё нет бонусов» — это ноль
        // бонусов, а не исключение и не отсутствующая строка в ответе.
        $user = \Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId);

        $balance = app(BonusService::class)->balanceFor($user);

        $this->assertTrue($balance->isZero());
        $this->assertSame('RUB', $balance->currency());
    }
}
