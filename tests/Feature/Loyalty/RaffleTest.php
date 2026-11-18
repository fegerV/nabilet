<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Jobs\EnterRafflesJob;
use Nabilet\Modules\Loyalty\Models\Raffle;
use Nabilet\Modules\Loyalty\Models\RaffleEntry;
use Nabilet\Modules\Loyalty\Models\RaffleWinner;
use Nabilet\Modules\Loyalty\Services\RaffleService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Розыгрыши «счастливый билет» (фаза 5 `docs/LOYALTY-PLAN.md`).
 *
 * ЗДЕСЬ ПРОВЕРЯЕТСЯ ПОЛНЫЙ КОНТУР, А НЕ ОДНА ФУНКЦИЯ
 *
 *   1. УЧАСТИЕ — СЛЕДСТВИЕ ОПЛАТЫ. Билет заводит `OrderRaffleObserver`, а не
 *      кнопку «участвовать»: каждый оплаченный заказ окна становится билетом
 *      автоматически. Тест платит заказ и видит строку в `raffle_entries` —
 *      без явного вызова сервиса из теста.
 *   2. ИДЕМПОТЕНТНОСТЬ. Повторная оплата (или повторный обсервер из-за
 *      ретрая) не дублирует билет: UNIQUE (raffle_id, order_id) ловится в
 *      `RaffleEntry::remember()`. Тест платит дважды — счётчик не растёт.
 *   3. ТИРАЖ РАСПРЕДЕЛЯЕТ ПРИЗЫ ПО МЕСТАМ. `draw()` делает ровно столько
 *      победителей, сколько призов, и ставит `drawn` + `drawn_at`.
 *   4. ТИРАЖ НЕ ПРОВОДИТСЯ БЕЗ УЧАСТНИКОВ. Призов больше, чем билетов, —
 *      `RAFFLE_NOT_ENOUGH_ENTRIES` (422). Это правило домена, а не ошибка
 *      ввода, поэтому код проверяется в теле ответа.
 *   5. ПОВТОРНЫЙ ТИРАЖ ЗАПРЕЩЁН. `drawn` терминален: вторая попытка —
 *      `RAFFLE_ALREADY_DRAWN` (422).
 *   6. ПУБЛИЧНАЯ СТРАНИЦА МАСКИРУЕТ ПОБЕДИТЕЛЯ. `GET /raffles/{public_id}`
 *      отдаёт только маску («Иван П.» / «i***@mail.ru»), а не имя и адрес
 *      целиком; черновик, отменённый и несуществующий розыгрыш — 404.
 *   7. /me/raffles ТРЕБУЕТ АККАУНТА. Гость получает 401, авторизованный
 *      видит свои розыгрыши с отметкой о выигрыше.
 */
class RaffleTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const TOKEN = '11111111-2222-3333-4444-555555555555';

    /**
     * Организация этого прогона — общая для розыгрыша и администратора.
     *
     * `raffles` не имеет однозначного родителя (`docs` фазы 3): организация
     * приходит из контекста запроса, а тест, вызывающий сервис напрямую,
     * обязан этот контекст выставить сам. Иначе NOT NULL не даст создать
     * розыгрыш — ровно та же причина, по которой в бою его выставляет
     * `ResolveOrganizationContext`.
     */
    private ?int $organizationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Обсервер оплаты ставит `EnterRafflesJob` в очередь; в тесте очередь
        // не крутится, поэтому фейкаем её и прогоняем джобу вручную — ровно как
        // `ReferralTest` прогоняет `RewardReferralJob`.
        Bus::fake();
    }

    private function user(string $email): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    private function tokenFor(User $user): string
    {
        return $this->app->make(SessionIssuer::class)->issue($user->id);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function apiToken(): string
    {
        return $this->tokenFor($this->staffUser());
    }

    private function staffUser(): User
    {
        $now = now();

        $organizationId = $this->organization();

        $user = $this->user('staff@example.com');

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        // Членство в `user_organization` — то, откуда
        // `ResolveOrganizationContext` берёт арендатора для staff-токена
        // (роль делает пользователя сотрудником, членство говорит, ЧЬИМ).
        // Без него контекст на админском HTTP-пути пуст, и скоуп Raffle
        // корректно отказывает. См. `OrganizationContextResolutionTest`.
        DB::table('user_organization')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
    }

    /** Организация прогона: создаётся один раз, чтобы розыгрыш и админ жили в одной. */
    private function organization(): int
    {
        if ($this->organizationId !== null) {
            return $this->organizationId;
        }

        $now = now();

        return $this->organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Raffle Org',
            'slug' => 'raffle-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Создать розыгрыш сервисом напрямую — с контекстом его организации.
     *
     * Контекст снимается после вызова: заказы в этих тестах создаются через
     * `seedSellableSeat()` в СВОЕЙ организации, и оставленный контекст
     * заставил бы `TenantScoped` отвергнуть явно чужой `organization_id`.
     */
    private function createRaffle(array $attributes, array $prizes = []): Raffle
    {
        $context = app(OrganizationContext::class);
        $context->set((string) $this->organization());

        try {
            return $this->raffles()->create($attributes, $prizes);
        } finally {
            $context->clear();
        }
    }

    private function raffles(): RaffleService
    {
        return app(RaffleService::class);
    }

    /**
     * Розыгрыш «за всё время» (окно не ограничено) с призами по местам.
     *
     * Создаётся черновиком и сразу активируется — именно так организатор
     * открывает набор участников. Backfill при активации переберёт оплаченные
     * заказы окна, но в тестах заказы создаются ПОСЛЕ активации, поэтому
     * участников добавит обсервер оплаты.
     *
     * @param  list<array{place: int, title: string}>  $prizes
     */
    private function activeRaffle(array $prizes, ?string $startsAt = null, ?string $endsAt = null): Raffle
    {
        $raffle = $this->createRaffle(
            ['title' => 'Счастливый билет', 'description' => 'Тест', 'starts_at' => $startsAt, 'ends_at' => $endsAt],
            $prizes,
        );

        return $this->raffles()->activate($raffle);
    }

    /**
     * Оплаченный заказ покупателя — источник билета.
     *
     * Именно переход в `paid` будит `OrderRaffleObserver`, поэтому возвращаем
     * свежий заказ уже в статусе оплаты.
     */
    private function paidOrder(User $user): Order
    {
        // Розыгрыш и заказ ОБЯЗАНЫ быть одного арендатора: сервис теперь
        // фильтрует organization_id (иначе билеты organization B попадали
        // в розыгрыш organization A). Тест использует ту же organization(),
        // что и createRaffle().
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(
            available: 1,
            organizationId: $this->organization(),
        );

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());

        $order = $order->fresh();

        // Обсервер поставил джобу в очередь: прогоняем её вручную, чтобы
        // билет гарантированно завёлся до проверок.
        (new EnterRafflesJob(orderId: $order->id))->handle($this->raffles());

        return $order;
    }

    // ── 1. автоматическое участие по оплате ──────────────────────────────────

    public function test_paid_order_becomes_an_entry_automatically(): void
    {
        $user = $this->user('buyer@example.com');
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);

        $order = $this->paidOrder($user);

        self::assertSame(1, $this->entryCount($raffle->id));
        self::assertSame($order->id, $this->entryOrderId($raffle->id));
    }

    // ── 2. идемпотентность ─────────────────────────────────────────────────

    public function test_repeat_payment_does_not_duplicate_the_entry(): void
    {
        $user = $this->user('buyer@example.com');
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);

        $order = $this->paidOrder($user);

        // Повторный заход джобы (ретрай воркера) по тому же заказу: `remember()`
        // ловит UNIQUE (raffle_id, order_id), второго билета не будет.
        (new EnterRafflesJob(orderId: $order->id))->handle($this->raffles());

        self::assertSame(1, $this->entryCount($raffle->id));
    }

    // ── 3. тираж распределяет призы ─────────────────────────────────────────

    public function test_draw_assigns_one_winner_per_prize(): void
    {
        $raffle = $this->activeRaffle([
            ['place' => 1, 'title' => 'Первое'],
            ['place' => 2, 'title' => 'Второе'],
            ['place' => 3, 'title' => 'Третье'],
        ]);

        foreach (range(1, 3) as $i) {
            $this->paidOrder($this->user("buyer{$i}@example.com"));
        }

        self::assertSame(3, $this->entryCount($raffle->id));

        $winners = $this->drawScoped($raffle->fresh());

        self::assertCount(3, $winners);
        self::assertSame(
            [1, 2, 3],
            $winners->pluck('prize.place')->sort()->values()->all(),
        );

        $drawn = $raffle->fresh();
        self::assertSame(Raffle::STATUS_DRAWN, $drawn->status);
        self::assertNotNull($drawn->drawn_at);
        self::assertSame(3, $this->winnerCount($raffle->id));
    }

    // ── 4. тираж не проводится без участников ──────────────────────────────

    public function test_draw_refuses_when_there_are_not_enough_entries(): void
    {
        $raffle = $this->activeRaffle([
            ['place' => 1, 'title' => 'Первое'],
            ['place' => 2, 'title' => 'Второе'],
        ]);

        $this->paidOrder($this->user('only@example.com'));

        $this->postJson('/api/v1/admin/raffles/' . $raffle->public_id . '/draw', [], $this->headers($this->apiToken()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RAFFLE_NOT_ENOUGH_ENTRIES');

        self::assertSame(Raffle::STATUS_ACTIVE, $raffle->fresh()->status, 'нехватка участников не должна менять статус');
    }

    // ── 5. повторный тираж запрещён ─────────────────────────────────────────

    public function test_draw_cannot_be_repeated(): void
    {
        $raffle = $this->activeRaffle([
            ['place' => 1, 'title' => 'Первое'],
            ['place' => 2, 'title' => 'Второе'],
        ]);

        foreach (range(1, 2) as $i) {
            $this->paidOrder($this->user("buyer{$i}@example.com"));
        }

        $this->drawScoped($raffle->fresh());
        self::assertSame(Raffle::STATUS_DRAWN, $raffle->fresh()->status);

        $this->postJson('/api/v1/admin/raffles/' . $raffle->public_id . '/draw', [], $this->headers($this->apiToken()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RAFFLE_ALREADY_DRAWN');
    }

    // ── 6. публичная страница: маскировка и видимость ──────────────────────

    public function test_public_results_mask_the_winner_and_hide_raw_details(): void
    {
        $user = $this->user('ivan.petrov@example.com');
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Главный приз']]);
        $this->paidOrder($user);
        $this->drawScoped($raffle->fresh());

        $res = $this->getJson('/api/v1/raffles/' . $raffle->public_id)->assertOk()->json('data');

        self::assertNotNull($res['prizes'][0]['winner'] ?? null, 'у разыгранного приза должен быть победитель');
        // Маска, а не полное имя/адрес: «Иван П.» или «i***@mail.ru».
        $display = $res['prizes'][0]['winner']['display'];
        self::assertNotEmpty($display);
        self::assertNotSame('ivan.petrov@example.com', $display, 'полный адрес не должен уходить наружу');
        // Ни имени, ни адреса целиком в полезной нагрузке быть не должно.
        self::assertArrayNotHasKey('customer_email', $res, 'публичный ответ не содержит адреса');
        self::assertArrayNotHasKey('name', $res, 'публичный ответ не содержит имя победителя');
    }

    public function test_public_results_are_hidden_for_draft(): void
    {
        $raffle = $this->createRaffle(['title' => 'Черновик'], [['place' => 1, 'title' => 'Приз']]);

        $this->getJson('/api/v1/raffles/' . $raffle->public_id)->assertStatus(404);
    }

    public function test_public_results_are_hidden_for_cancelled(): void
    {
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);
        $this->raffles()->cancel($raffle);

        $this->getJson('/api/v1/raffles/' . $raffle->public_id)->assertStatus(404);
    }

    public function test_public_results_are_404_for_unknown_id(): void
    {
        $this->getJson('/api/v1/raffles/NEVEREXISTEDID12345678')->assertStatus(404);
    }

    // ── 7. /me/raffles ─────────────────────────────────────────────────────

    public function test_my_raffles_require_an_account(): void
    {
        $this->getJson('/api/v1/me/raffles')->assertStatus(401);
    }

    public function test_my_raffles_list_participations_with_a_win_flag(): void
    {
        $user = $this->user('winner@example.com');
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Главный приз']]);
        $this->paidOrder($user);
        $this->drawScoped($raffle->fresh());

        $res = $this->getJson('/api/v1/me/raffles', $this->headers($this->tokenFor($user)))
            ->assertOk()
            ->json('data');

        self::assertCount(1, $res);
        self::assertSame($raffle->public_id, $res[0]['public_id']);
        self::assertSame(1, $res[0]['entries_count']);
        self::assertNotEmpty($res[0]['won']);
        self::assertSame(1, $res[0]['won'][0]['place']);
        self::assertSame('Главный приз', $res[0]['won'][0]['prize_title']);
    }

    // ── регрессии аудита 2026-11-18 ────────────────────────────────────────

    public function test_paid_order_of_another_organization_does_not_enter_the_raffle(): void
    {
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);

        // Заказ СВОЕЙ организации (отличной от розыгрыша): без фильтра он
        // становился билетом чужого розыгрыша.
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);
        self::assertNotSame($this->organization(), $organizationId);

        $service = app(OrderService::class);
        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'other-org@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);
        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());
        (new EnterRafflesJob(orderId: $order->id))->handle($this->raffles());

        self::assertSame(0, $this->entryCount($raffle->id));
    }

    public function test_active_raffle_window_cannot_be_changed(): void
    {
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);

        $this->putJson('/api/v1/admin/raffles/' . $raffle->public_id, [
            'starts_at' => now()->addDay()->toIso8601String(),
        ], $this->headers($this->apiToken()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RAFFLE_ACTIVE_WINDOW_IMMUTABLE');
    }

    public function test_pause_allows_window_edit_and_reactivation(): void
    {
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);
        $headers = $this->headers($this->apiToken());

        $this->postJson('/api/v1/admin/raffles/' . $raffle->public_id . '/pause', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', Raffle::STATUS_DRAFT);

        $startsAt = now()->subDay()->toIso8601String();
        $this->putJson('/api/v1/admin/raffles/' . $raffle->public_id, [
            'starts_at' => $startsAt,
        ], $headers)->assertOk();

        $this->postJson('/api/v1/admin/raffles/' . $raffle->public_id . '/activate', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', Raffle::STATUS_ACTIVE);
    }

    public function test_notify_requires_a_drawn_raffle(): void
    {
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Приз']]);

        $this->postJson('/api/v1/admin/raffles/' . $raffle->public_id . '/notify', [], $this->headers($this->apiToken()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'RAFFLE_NOT_DRAWN');
    }

    public function test_my_raffles_finds_guest_entries_by_email_after_registration(): void
    {
        $buyer = $this->user('guest-buyer@example.com');
        $raffle = $this->activeRaffle([['place' => 1, 'title' => 'Главный приз']]);

        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1, organizationId: $this->organization());
        $service = app(OrderService::class);
        $order = $service->createOrder([
            'organization_id' => $organizationId,
            // Гость: user_id не задан, участвует по e-mail заказа.
            'customer_email' => $buyer->email,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);
        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());
        (new EnterRafflesJob(orderId: $order->id))->handle($this->raffles());

        $res = $this->getJson('/api/v1/me/raffles', $this->headers($this->tokenFor($buyer)))
            ->assertOk()
            ->json('data');

        self::assertCount(1, $res);
        self::assertSame($raffle->public_id, $res[0]['public_id']);
    }

    public function test_my_raffles_returns_every_win_not_only_the_first(): void
    {
        $buyer = $this->user('two-wins@example.com');
        $raffle = $this->activeRaffle([
            ['place' => 1, 'title' => 'Первый'],
            ['place' => 2, 'title' => 'Второй'],
        ]);

        $this->paidOrder($buyer);
        $this->paidOrder($buyer);
        $this->drawScoped($raffle->fresh());

        $res = $this->getJson('/api/v1/me/raffles', $this->headers($this->tokenFor($buyer)))
            ->assertOk()
            ->json('data');

        self::assertCount(2, $res[0]['won']);
        self::assertSame(
            [1, 2],
            collect($res[0]['won'])->pluck('place')->sort()->values()->all(),
        );
    }

    // ── тест-аналоги системных путей (bypass) ──────────────────────────────

    /**
     * Тест-аналог админского тиража: в проде `draw()` зовёт staff-запрос
     * (POST /admin/raffles/{id}/draw, контекст выставлен мидлварью), тест
     * зовёт сервис напрямую — в bypass. Связь `prize` дозагружается тут же:
     * админский ответ лениво тянет её уже под контекстом, тест — в этом же
     * bypass-замыкании.
     *
     * @return \Illuminate\Support\Collection<int, RaffleWinner>
     */
    private function drawScoped(Raffle $raffle): \Illuminate\Support\Collection
    {
        return app(OrganizationContext::class)->withoutScope(
            function () use ($raffle): \Illuminate\Support\Collection {
                $winners = $this->raffles()->draw($raffle);
                $winners->each(static fn (RaffleWinner $winner): RaffleWinner => $winner->load('prize'));

                return $winners;
            }
        );
    }

    private function entryCount(int $raffleId): int
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): int => RaffleEntry::query()->where('raffle_id', $raffleId)->count()
        );
    }

    private function entryOrderId(int $raffleId): ?int
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): ?int => RaffleEntry::query()->where('raffle_id', $raffleId)->value('order_id')
        );
    }

    private function winnerCount(int $raffleId): int
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): int => RaffleWinner::query()->where('raffle_id', $raffleId)->count()
        );
    }
}
