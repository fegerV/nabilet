<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        DB::table('organizations')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Raffle Org',
            'slug' => 'raffle-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->user('staff@example.com');

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => DB::table('organizations')->latest('id')->value('id'),
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
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
        $raffle = $this->raffles()->create(
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
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

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

        self::assertSame(1, RaffleEntry::query()->where('raffle_id', $raffle->id)->count());
        self::assertSame($order->id, RaffleEntry::query()->where('raffle_id', $raffle->id)->value('order_id'));
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

        self::assertSame(1, RaffleEntry::query()->where('raffle_id', $raffle->id)->count());
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

        self::assertSame(3, RaffleEntry::query()->where('raffle_id', $raffle->id)->count());

        $winners = $this->raffles()->draw($raffle->fresh());

        self::assertCount(3, $winners);
        self::assertSame(
            [1, 2, 3],
            $winners->pluck('prize.place')->sort()->values()->all(),
        );

        $drawn = $raffle->fresh();
        self::assertSame(Raffle::STATUS_DRAWN, $drawn->status);
        self::assertNotNull($drawn->drawn_at);
        self::assertSame(3, RaffleWinner::query()->where('raffle_id', $raffle->id)->count());
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

        $this->raffles()->draw($raffle->fresh());
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
        $this->raffles()->draw($raffle->fresh());

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
        $raffle = $this->raffles()->create(['title' => 'Черновик'], [['place' => 1, 'title' => 'Приз']]);

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
        $this->raffles()->draw($raffle->fresh());

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
}
