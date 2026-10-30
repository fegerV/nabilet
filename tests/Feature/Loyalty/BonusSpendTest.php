<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Cart\Support\CartToken;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Jobs\AccrueOrderBonusJob;
use Nabilet\Modules\Loyalty\Jobs\RefundOrderBonusJob;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Клуб зрителей», фаза 2b: списание бонусов в чекауте.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. СУММА. `total = subtotal - discount - bonus`. Бонусы — вычитаемый член,
 *      а не «магическая скидка»: `orders.bonus_amount` заполняется отдельно от
 *      `discount_amount`, и покупатель видит свою строку. Проверяется на живом
 *      чекауте, а не на арифметике: сумма приходит из `OrderTotals`, и только
 *      сквозной прогон показывает, что чекаут её действительно использует.
 *   2. ЛИМИТ. Меньшее из трёх: 30 % от подытога, остаток после скидки, баланс.
 *      Списание без лимита — это подарок за счёт организатора, а не недоделанная
 *      фича; поэтому тесты бьют и по «больше баланса», и по «больше 30 %».
 *   3. ОТКАЗ, А НЕ ТИХОЕ УРЕЗАНИЕ. Покупатель подтверждает конкретную цену.
 *      Если он попросил 300, а сервер списал 100, он заплатит больше, чем
 *      согласился. Поэтому превышение — это 422 `BONUS_LIMIT_EXCEEDED`, и тест
 *      проверяет, что после отказа не осталось НИ СЛЕДА: ни заказа, ни строки
 *      журнала, ни сдвинутого баланса, а корзина осталась активной.
 *   4. КОНТУР ОПЛАТЫ, А НЕ ОДИН ЕГО ШАГ. Кэшбэк начисляется от `total_amount`
 *      (фаза 2a), и после списания это уже НЕ подытог. Тест доводит заказ до
 *      `paid` через платёж и вебхук и проверяет, что начислено 5 % от того, что
 *      покупатель реально заплатил, — иначе система платила бы кэшбэк с денег,
 *      которых не получала.
 *   5. КОМПЕНСАЦИЯ. Бонусы списываются в момент оформления (см.
 *      `BonusService::spendForOrder()`), поэтому несостоявшаяся покупка обязана
 *      их вернуть: отмена, истечение срока оплаты и полный возврат. Возврат
 *      идемпотентен — повтор джобы не начисляет второй раз.
 *
 * ПОЧЕМУ ДЖОБЫ ЗАПУСКАЮТСЯ ВРУЧНУЮ
 *   Оба наблюдателя ставят джобу в очередь `database` (`onConnection`), а
 *   `Bus::fake()` её перехватывает — так тест видит ФАКТ постановки, но не
 *   зависит от воркера. Исполнение моделируется вызовом `handle()`, то есть тем
 *   же кодом, который выполнит воркер.
 *
 * ПАЛИТРА СУММ. Подытог 100 000 минорных единиц (1 000 ₽) выбран так, чтобы
 * 30 % давали ровное 30 000, а кэшбэк 5 % — ровные 3 500. Тогда любое расхождение
 * в тесте — это ошибка логики, а не спор об округлении.
 */
class BonusSpendTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const SUBTOTAL = 100_000;
    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const EMAIL = 'buyer@example.com';

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = $this->app->make(PaymentService::class);
    }

    // ── фикстуры ───────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function cartHeaders(string $token = self::TOKEN): array
    {
        return [CartToken::HEADER => $token];
    }

    /** Покупатель с адресом заказа: именно по нему чекаут находит владельца. */
    private function seedBuyer(string $email = self::EMAIL): int
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
     * Счёт с готовым балансом.
     *
     * Баланс ЗАВОДИТСЯ ВМЕСТЕ СО СТРОКОЙ ЖУРНАЛА, а не одной вставкой в
     * `bonus_accounts`. В этой схеме баланс — кэш журнала (`BonusAccount`), и
     * посев «просто числа» сломал бы инвариант «баланс = SUM(delta)» самим
     * тестом, а не кодом: проверка на расхождение краснела бы на фикстуре.
     * `registration` — законная причина для подарка, не привязанного к заказу.
     */
    private function seedBonusAccount(int $userId, int $balanceMinor): void
    {
        $now = now()->toDateTimeString();

        DB::table('bonus_accounts')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'balance' => $balanceMinor,
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('bonus_transactions')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'order_id' => null,
            'delta' => $balanceMinor,
            'reason' => BonusReason::REGISTRATION,
            'dedupe_key' => null,
            'created_at' => $now,
        ]);
    }

    private function addToCart(int $sessionId, int $inventoryItemId, int $quantity = 1): void
    {
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => $quantity,
        ], $this->cartHeaders())->assertStatus(201);
    }

    /**
     * Оформить заказ из корзины.
     *
     * @param array<string, mixed> $extra дополнительные поля тела (`bonus_amount`)
     */
    private function checkout(int $sessionId, array $extra = [], string $email = self::EMAIL)
    {
        return $this->postJson('/api/v1/cart/checkout', array_merge([
            'session_id' => $sessionId,
            'customer_email' => $email,
            'customer_email_confirmation' => $email,
            'customer_name' => 'Иван Петров',
        ], $extra), $this->cartHeaders());
    }

    private function orderByPublicId(string $publicId): Order
    {
        // Тест читает конкретный заказ по public_id вне HTTP-контекста
        // (гостевое checkout не устанавливает организацию). Глобальный скоуп
        // `Order` (фаза 2) в этом случае нужно обойти явно.
        return app(OrganizationContext::class)->withoutScope(
            fn (): Order => Order::query()->where('public_id', $publicId)->firstOrFail()
        );
    }

    private function balanceOf(int $userId): int
    {
        return (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance');
    }

    /**
     * Строки журнала по причине, свежие сверху.
     *
     * По причине, а не по индексу: у счёта в тестах есть ещё и подарочная
     * строка посева, и «третья строка списка» — это проверка порядка вставки,
     * а не поведения.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function ledgerByReason(int $userId, string $reason)
    {
        return DB::table('bonus_transactions')
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->orderBy('id')
            ->get();
    }

    private function ledgerCount(int $userId, string $reason): int
    {
        return DB::table('bonus_transactions')
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->count();
    }

    private function runAccrual(int $orderId): void
    {
        (new AccrueOrderBonusJob($orderId))->handle($this->app->make(BonusService::class));
    }

    private function runRefund(int $orderId): void
    {
        (new RefundOrderBonusJob($orderId))->handle($this->app->make(BonusService::class));
    }

    // ── 1. сумма ───────────────────────────────────────────────────────────

    public function test_checkout_spends_bonuses_and_stores_the_amount(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);

        // 1 000 ₽ - 300 ₽ бонусами = 700 ₽.
        $this->assertSame(self::SUBTOTAL, (int) $response->json('data.subtotal_amount'));
        $this->assertSame(0, (int) $response->json('data.discount_amount'));
        $this->assertSame(30_000, (int) $response->json('data.bonus_amount'));
        $this->assertSame(70_000, (int) $response->json('data.total_amount'));

        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        // Бонусы — отдельная колонка, а не часть скидки: разбор заказа обязан
        // отличать «уступили по коду» от «вернули лояльностью».
        $this->assertSame(30_000, (int) $order->bonus_amount);
        $this->assertSame(0, (int) $order->discount_amount);
        $this->assertSame(70_000, (int) $order->total_amount);
        $this->assertSame($userId, (int) $order->user_id);

        $spends = $this->ledgerByReason($userId, BonusReason::SPEND);
        $this->assertCount(1, $spends);
        $this->assertSame(-30_000, (int) $spends[0]->delta);
        // Ключ идемпотентности — часть контракта с базой, а не деталь.
        $this->assertSame('spend:' . $order->id, $spends[0]->dedupe_key);

        $this->assertSame(20_000, $this->balanceOf($userId));
        // Баланс — кэш журнала: расхождение «в журнале одно, на счёте другое»
        // всплыло бы только при сверке.
        $this->assertSame(
            $this->balanceOf($userId),
            (int) DB::table('bonus_transactions')->where('user_id', $userId)->sum('delta'),
        );
    }

    public function test_an_order_without_bonuses_is_unchanged(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        // Поля нет вовсе — так оформляет гость и любой клиент, который про бонусы
        // не знает. Поведение обязано остаться прежним.
        $response = $this->checkout($sessionId)->assertStatus(200);

        $this->assertSame(0, (int) $response->json('data.bonus_amount'));
        $this->assertSame(self::SUBTOTAL, (int) $response->json('data.total_amount'));
        $this->assertSame(50_000, $this->balanceOf($userId));
        $this->assertSame(0, $this->ledgerCount($userId, BonusReason::SPEND));
    }

    // ── 2. лимит ───────────────────────────────────────────────────────────

    public function test_the_cap_is_thirty_percent_of_the_subtotal(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        // Баланса заведомо больше лимита: ограничивать должно правило 30 %.
        $this->seedBonusAccount($userId, 5_000_000);

        $this->addToCart($sessionId, $inventoryItemId);

        // Ровно лимит — проходит.
        $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);

        $this->assertSame(4_970_000, $this->balanceOf($userId));
    }

    public function test_the_cap_is_limited_by_the_balance(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        // Баланса меньше, чем 30 % от подытога: ограничивать должен баланс.
        $this->seedBonusAccount($userId, 10_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 10_000])->assertStatus(200);

        $this->assertSame(10_000, (int) $response->json('data.bonus_amount'));
        $this->assertSame(90_000, (int) $response->json('data.total_amount'));
        $this->assertSame(0, $this->balanceOf($userId));
    }

    public function test_a_request_above_the_cap_is_refused_and_leaves_no_trace(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        // 30 001 при лимите 30 000 — отказ. Урезать молча нельзя: покупатель
        // подтверждал другую цену.
        $this->checkout($sessionId, ['bonus_amount' => 30_001])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BONUS_LIMIT_EXCEEDED');

        // Отказ внутри транзакции оформления откатывает ВСЁ: ни заказа, ни
        // строки списания, ни сдвинутого баланса — и корзина остаётся активной,
        // чтобы покупатель мог повторить без бонусов.
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, $this->ledgerCount($userId, BonusReason::SPEND));
        $this->assertSame(50_000, $this->balanceOf($userId));
        $this->assertSame('active', DB::table('carts')->value('status'));
    }

    public function test_a_guest_without_an_account_cannot_spend(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        // Покупателя нет вовсе: счёта тоже нет, лимит — ноль.
        $this->addToCart($sessionId, $inventoryItemId);

        $this->checkout($sessionId, ['bonus_amount' => 30_000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BONUS_LIMIT_EXCEEDED');

        $this->assertSame(0, DB::table('orders')->count());
    }

    // ── 3. полный контур оплаты ────────────────────────────────────────────

    public function test_the_full_payment_circuit_accrues_from_the_reduced_total(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        // Платёж создаётся на ИТОГ заказа, а не на подытог: иначе покупатель
        // заплатил бы деньгами всю сумму и вдобавок лишился бонусов.
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-bonus-1',
        ]);

        $this->assertSame(70_000, (int) $payment->amount);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-bonus-1',
                'status' => 'succeeded',
                // ЮKassa всегда присылает сумму; вебхук сверяет её с заказом.
                'amount' => ['value' => '700.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->assertSame(
            OrderStateMachine::PAID,
            (string) DB::table('orders')->where('id', $order->id)->value('status'),
        );

        // Оплата ставит начисление в очередь — проверяем сам факт и запускаем
        // ровно тот код, который выполнит воркер.
        Bus::assertDispatched(AccrueOrderBonusJob::class);
        $this->runAccrual($order->id);

        // 5 % от 70 000 = 3 500, а не 5 % от 100 000. Разница — это деньги,
        // которых организатор не получал.
        $accruals = $this->ledgerByReason($userId, BonusReason::ACCRUAL);
        $this->assertCount(1, $accruals);
        $this->assertSame(3_500, (int) $accruals[0]->delta);

        $this->assertSame(20_000 + 3_500, $this->balanceOf($userId));
        $this->assertSame(
            $this->balanceOf($userId),
            (int) DB::table('bonus_transactions')->where('user_id', $userId)->sum('delta'),
        );
    }

    // ── 4. компенсация ─────────────────────────────────────────────────────

    public function test_cancelling_an_unpaid_order_returns_the_bonuses(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        $this->assertSame(20_000, $this->balanceOf($userId));

        $this->app->make(OrderService::class)->cancelOrder($order);

        Bus::assertDispatched(RefundOrderBonusJob::class);
        $this->runRefund($order->id);

        $refunds = $this->ledgerByReason($userId, BonusReason::REFUND);
        $this->assertCount(1, $refunds);
        $this->assertSame(30_000, (int) $refunds[0]->delta);
        $this->assertSame('refund:' . $order->id, $refunds[0]->dedupe_key);

        // Покупка не состоялась — бонусы вернулись целиком.
        $this->assertSame(50_000, $this->balanceOf($userId));
    }

    public function test_expiry_returns_the_bonuses(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        // Свипера срока оплаты в коде ещё нет (`orders:expire-sweeper` упомянут
        // в комментарии, но команды не существует), поэтому переход выставляется
        // напрямую. Наблюдателю важен САМ факт перехода в терминальный статус —
        // именно на него он и подписан.
        $order->update(['status' => OrderStateMachine::EXPIRED]);

        Bus::assertDispatched(RefundOrderBonusJob::class);
        $this->runRefund($order->id);

        $this->assertSame(50_000, $this->balanceOf($userId));
    }

    public function test_payment_failure_does_not_return_the_bonuses(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        $this->app->make(OrderService::class)->markPaymentFailed($order);

        // `payment_failed` НЕ терминальный: покупатель может повторить оплату
        // другой картой. Вернуть бонусы здесь значило бы вернуть их заказу,
        // который ещё может быть оплачен.
        Bus::assertNotDispatched(RefundOrderBonusJob::class);
        $this->assertSame(20_000, $this->balanceOf($userId));
        $this->assertSame(0, $this->ledgerCount($userId, BonusReason::REFUND));
        $this->assertSame(1, $this->ledgerCount($userId, BonusReason::SPEND));
    }

    public function test_returning_the_bonuses_twice_returns_them_once(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        // Повтор возможен в жизни: наблюдатель сработал на `cancelled`, затем
        // админский сценарий выставил `expired`. Разрешение выдаёт
        // `uq_bonus_transactions_dedupe`, а не SELECT «уже вернули?».
        $this->runRefund($order->id);
        $this->runRefund($order->id);

        // Одна строка, а не две: разрешение выдаёт `uq_bonus_transactions_dedupe`.
        $this->assertSame(1, $this->ledgerCount($userId, BonusReason::REFUND));
        $this->assertSame(50_000, $this->balanceOf($userId));
    }

    public function test_a_refund_returns_the_bonuses_with_the_money(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-refund-1',
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'yk-refund-1',
                'status' => 'succeeded',
                'amount' => ['value' => '700.00', 'currency' => 'RUB'],
            ],
        ]);

        $this->runAccrual($order->id);
        $this->assertSame(23_500, $this->balanceOf($userId));

        // Провайдер подделан: возврат уходит реальным HTTP-запросом, и без
        // подмены тест стучался бы в ЮKassa (и падал бы на сертификате, а не на
        // логике). Схема ответа — как в `PaymentWritePathTest`.
        config([
            'nabilet.payment.yookassa.shop_id' => 'test-shop',
            'nabilet.payment.yookassa.secret_key' => 'test-secret',
        ]);
        Http::fake([
            '*/refunds' => Http::response([
                'id' => 'rf-bonus-1',
                'status' => 'succeeded',
                'amount' => ['value' => '700.00', 'currency' => 'RUB'],
            ], 200),
        ]);

        // Полный возврат: покупатель вернул деньги — и бонусная часть платежа
        // обязана вернуться вместе с ними, иначе возврат «в бонусах» пропадает
        // молча. `RefundService` двигает статус через машину состояний, и это
        // единственный писатель `refunded`.
        $this->app->make(PaymentService::class)->refundPayment($payment->fresh(), 70_000, 'покупатель передумал');

        $this->assertSame(
            OrderStateMachine::REFUNDED,
            (string) DB::table('orders')->where('id', $order->id)->value('status'),
        );

        Bus::assertDispatched(RefundOrderBonusJob::class);
        $this->runRefund($order->id);

        // Списания больше нет, а кэшбэк за возвращённый заказ — есть: отзыв
        // начисления на возврате не реализован (известное ограничение фазы 2b,
        // записано в `docs/LOYALTY-PLAN.md`). Поэтому итог — это посев 50 000
        // плюс незачтённые 3 500, а не 20 000. Разница видна здесь ровно
        // потому, что тест считает баланс, а не проверяет «стало больше».
        $this->assertSame(50_000 + 3_500, $this->balanceOf($userId));
        $this->assertSame(1, $this->ledgerCount($userId, BonusReason::REFUND));
        $this->assertSame(
            $this->balanceOf($userId),
            (int) DB::table('bonus_transactions')->where('user_id', $userId)->sum('delta'),
        );
    }

    // ── 5. инварианты сервиса ──────────────────────────────────────────────

    public function test_spending_more_than_the_balance_is_refused_by_the_service(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 1_000);

        $this->addToCart($sessionId, $inventoryItemId);
        $response = $this->checkout($sessionId)->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        // Сервис — публичная точка входа: команда пересчёта или ручной вызов не
        // должны получить возможность списать больше лимита в обход чекаута.
        $this->expectException(DomainRuleViolation::class);

        try {
            $this->app->make(BonusService::class)->spendForOrder(
                $order,
                \Nabilet\Core\Support\Money::of(50_000),
                \Nabilet\Core\Support\Money::of(self::SUBTOTAL),
            );
        } finally {
            // Отказ откатывает транзакцию сервиса целиком: строки списания и
            // сдвинутого баланса после него быть не должно.
            $this->assertSame(0, $this->ledgerCount($userId, BonusReason::SPEND));
            $this->assertSame(1_000, $this->balanceOf($userId));
        }
    }

    public function test_spending_zero_is_a_no_op(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);
        $response = $this->checkout($sessionId)->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        $result = $this->app->make(BonusService::class)->spendForOrder(
            $order,
            \Nabilet\Core\Support\Money::zero(),
            \Nabilet\Core\Support\Money::of(self::SUBTOTAL),
        );

        // Ноль — не ошибка и не строка «−0» в истории.
        $this->assertNull($result);
        $this->assertSame(0, $this->ledgerCount($userId, BonusReason::SPEND));
        $this->assertSame(50_000, $this->balanceOf($userId));
    }

    // ── 6. контракт ответа ─────────────────────────────────────────────────

    /**
     * Прямая форма заказа (`items`, без корзины) бонусов не знает.
     *
     * Списание — часть оформления КОРЗИНЫ: только там есть бонусный счёт
     * покупателя, лимит `BonusPolicy` и строка журнала. У прямого пути
     * (`OrderService::createOrder`) ничего этого нет, и `bonus_amount` в нём
     * записался бы в колонку БЕЗ движения по счёту — то есть покупатель получил
     * бы скидку, которой не оплачивал, а баланс остался бы прежним.
     *
     * Поэтому поле не игнорируется молча, а отвергается
     * (`prohibited_without:cart_id`): клиент, приславший сумму, обязан узнать,
     * что она ни на что не повлияла.
     */
    public function test_the_direct_order_form_rejects_bonuses(): void
    {
        Bus::fake();

        [$organizationId, , , $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->actingAs(\Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId), 'api');

        $body = [
            'organization_id' => $organizationId,
            'customer_email' => self::EMAIL,
            'customer_name' => 'Иван Петров',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ];

        // Контроль: без бонусов прямой путь работает как раньше.
        $this->postJson('/api/v1/orders', $body)->assertStatus(201);

        $this->postJson('/api/v1/orders', $body + ['bonus_amount' => 30_000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // Второй заказ не создан, баланс не тронут.
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(50_000, $this->balanceOf($userId));
        $this->assertSame(0, $this->ledgerCount($userId, BonusReason::SPEND));
    }

    public function test_the_order_resource_exposes_the_bonus_amount(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(
            available: 2,
            priceAmount: self::SUBTOTAL,
        );

        $userId = $this->seedBuyer();
        $this->seedBonusAccount($userId, 50_000);

        $this->addToCart($sessionId, $inventoryItemId);
        $response = $this->checkout($sessionId, ['bonus_amount' => 30_000])->assertStatus(200);
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));

        $this->actingAs(\Nabilet\Modules\Core\Users\Models\User::query()->findOrFail($userId), 'api');

        // `MoneyTotals` несёт `bonus_amount` отдельной строкой: покупатель должен
        // видеть, сколько заказа закрыто лояльностью, а не «итог меньше суммы
        // позиций» без объяснения.
        //
        // Адресуется заказ внутренним `id`, а не `public_id`: маршрут объявлен
        // как `whereNumber('order')` (`Orders/routes/api.php:26`), то есть
        // `GET /orders/{order}` принимает BIGINT. Это расходится с
        // `OrderResponse.id` (там `public_id`) и записано в отчёте как отдельная
        // находка, а не починено здесь: правка привязки маршрута — своя волна.
        $this->getJson('/api/v1/orders/' . $order->id)
            ->assertStatus(200)
            ->assertJsonPath('data.totals.bonus_amount', 30_000)
            ->assertJsonPath('data.totals.discount_amount', 0)
            ->assertJsonPath('data.totals.total_amount', 70_000)
            ->assertJsonPath('data.totals.subtotal_amount', self::SUBTOTAL);
    }
}
