<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Рекомендуем» (фаза 5): персональная полка мероприятий.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. ДОСТУП. Профиль вкуса — личный, поэтому путь закрыт `auth:api`: гостю
 *      строить рекомендации не из чего, и он получает 401, а не случайный
 *      каталог под видом персонального.
 *   2. ПРОФИЛЬ ВКУСА. Три сигнала — избранное, подписка и ОПЛАЧЕННЫЙ заказ —
 *      каждый по отдельности обязан привести к рекомендации мероприятия из той
 *      же категории. Неоплаченный заказ сигналом не считается.
 *   3. ИСКЛЮЧЕНИЕ ВЗАИМОДЕЙСТВОВАННОГО. Мероприятие, которое покупатель уже
 *      «полюбил» или купил, не должно рекомендоваться само себе.
 *   4. КАНДИДАТ ОБЯЗАН БЫТЬ ПОКУПАЕМЫМ. Черновик и мероприятие без будущих
 *      сеансов не показываются: «Рекомендуем» — это «сходи», а не «посмотри на
 *      архив».
 *   5. ВНУТРЕННИЕ ИДЕНТИФИКАТОРЫ НЕ ПОКИДАЮТ API. Ответ несёт `events.public_id`
 *      (ULID), и тест проверяет ОТСУТСТВИЕ `category_id`/`event_id`.
 *   6. ПУСТОЙ ПРОФИЛЬ — ПУСТАЯ ПОЛКА. У нового аккаунта `data: []`, а не
 *      подставленный общий каталог.
 *
 * Фикстуры берутся из `SellableSeatFixtures`: он собирает обязательную цепочку
 * `organizations → events → venues → halls → hall_schema_versions → sessions`
 * (у сеанса эти ключи NOT NULL), а категория события и дата сеанса патчатся
 * точечно — рекомендация зависит ровно от них.
 */
class RecommendationTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const PASSWORD = 'Kv7#mRta2!Qz';

    // ── фикстуры ───────────────────────────────────────────────────────────

    /**
     * Зарегистрировать покупателя и вернуть его токен и внутренний id.
     *
     * @return array{token: string, user_id: int}
     */
    private function buyer(string $email = 'buyer@example.com'): array
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');

        return [
            'token' => $token,
            'user_id' => (int) DB::table('users')->where('email', $email)->value('id'),
        ];
    }

    private function category(string $slug): int
    {
        return (int) DB::table('event_categories')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Категория ' . $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    /**
     * Мероприятие с сеансом (через полную цепочку фикстур) плюс категория и дата.
     *
     * @return array{event_id:int, public_id:string, session_id:int, org_id:int, inventory_id:int}
     */
    private function event(?int $categoryId, string $status = 'published', ?string $startsAt = null): array
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(available: 5);

        DB::table('events')->where('id', $eventId)->update([
            'category_id' => $categoryId,
            'status' => $status,
        ]);

        if ($startsAt !== null) {
            DB::table('sessions')->where('id', $sessionId)->update(['starts_at' => $startsAt]);
        }

        return [
            'event_id' => $eventId,
            'public_id' => (string) DB::table('events')->where('id', $eventId)->value('public_id'),
            'session_id' => $sessionId,
            'org_id' => $orgId,
            'inventory_id' => $inventoryId,
        ];
    }

    private function favorite(int $userId, int $eventId): void
    {
        DB::table('event_favorites')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'event_id' => $eventId,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    private function follow(int $userId, int $eventId): void
    {
        DB::table('event_follows')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'event_id' => $eventId,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Оплаченный заказ через настоящий контур (OrderService), а не вставкой
     * строки: так `event_id` заказа резолвится из сеанса ровно тем же кодом, что
     * в проде, и тест не может разойтись со схемой.
     */
    private function paidOrder(int $userId, array $event): void
    {
        Bus::fake();

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $event['org_id'],
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $event['inventory_id'], 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());
    }

    /** @return list<string> */
    private function recommendedIds(string $token, int $limit = 8): array
    {
        $data = $this->getJson("/api/v1/me/recommendations?limit={$limit}", [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk()->json('data');

        return array_map(static fn (array $row): string => (string) $row['id'], $data ?? []);
    }

    // ── 1. доступ ──────────────────────────────────────────────────────────

    public function test_recommendations_require_an_account(): void
    {
        $this->getJson('/api/v1/me/recommendations')->assertStatus(401);
    }

    public function test_a_new_account_gets_an_empty_shelf_not_the_whole_catalog(): void
    {
        ['token' => $token] = $this->buyer();
        $this->event($this->category('music-' . Str::random(5)));

        // Есть опубликованное мероприятие, но у покупателя нет ни одного сигнала:
        // показать его значило бы выдать общий каталог под видом персонального.
        $this->getJson('/api/v1/me/recommendations', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── 2. профиль вкуса ───────────────────────────────────────────────────

    public function test_a_favorite_builds_the_taste_profile_and_the_source_event_is_excluded(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('music-' . Str::random(5));

        $liked = $this->event($category);
        $similar = $this->event($category);

        $this->favorite($userId, $liked['event_id']);

        $ids = $this->recommendedIds($token);

        // Мероприятие из той же категории — в выдаче, «любимое» — нет.
        $this->assertContains($similar['public_id'], $ids, 'похожее мероприятие обязано попасть в рекомендации');
        $this->assertNotContains($liked['public_id'], $ids, 'мероприятие из избранного не рекомендуем само себе');
    }

    public function test_a_follow_also_builds_the_taste_profile(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('theatre-' . Str::random(5));

        $followed = $this->event($category);
        $similar = $this->event($category);

        $this->follow($userId, $followed['event_id']);

        $ids = $this->recommendedIds($token);

        $this->assertContains($similar['public_id'], $ids);
        $this->assertNotContains($followed['public_id'], $ids);
    }

    public function test_a_paid_order_builds_the_taste_profile(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('standup-' . Str::random(5));

        $bought = $this->event($category);
        $similar = $this->event($category);

        $this->paidOrder($userId, $bought);

        $ids = $this->recommendedIds($token);

        $this->assertContains($similar['public_id'], $ids);
        // Купленное не рекомендуем повторно — билет уже есть.
        $this->assertNotContains($bought['public_id'], $ids);
    }

    public function test_events_in_other_categories_are_not_recommended(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $likedCategory = $this->category('rock-' . Str::random(5));
        $otherCategory = $this->category('kids-' . Str::random(5));

        $liked = $this->event($likedCategory);
        $similar = $this->event($likedCategory);
        $unrelated = $this->event($otherCategory);

        $this->favorite($userId, $liked['event_id']);

        $ids = $this->recommendedIds($token);

        $this->assertContains($similar['public_id'], $ids);
        // Категория без сигнала в профиле не должна «подмешиваться».
        $this->assertNotContains($unrelated['public_id'], $ids);
    }

    // ── 3. кандидат обязан быть покупаемым ─────────────────────────────────

    public function test_an_event_without_future_sessions_is_not_recommended(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('jazz-' . Str::random(5));

        $liked = $this->event($category);
        // Похожее, но все сеансы в прошлом — показывать «сходи» не на что.
        $past = $this->event($category, startsAt: now()->subWeek()->toDateTimeString());

        $this->favorite($userId, $liked['event_id']);

        $ids = $this->recommendedIds($token);

        $this->assertNotContains($past['public_id'], $ids, 'мероприятие без будущих сеансов не рекомендуем');
    }

    public function test_a_draft_event_is_not_recommended(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('opera-' . Str::random(5));

        $liked = $this->event($category);
        $draft = $this->event($category, status: 'draft');

        $this->favorite($userId, $liked['event_id']);

        $ids = $this->recommendedIds($token);

        $this->assertNotContains($draft['public_id'], $ids, 'черновик не показываем на витрине');
    }

    // ── 4. форма ответа ────────────────────────────────────────────────────

    public function test_the_response_exposes_public_ids_only(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('lecture-' . Str::random(5));

        $liked = $this->event($category);
        $this->event($category);
        $this->favorite($userId, $liked['event_id']);

        $data = $this->getJson('/api/v1/me/recommendations', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);

        $first = $data[0];
        // `id` — это `events.public_id` (ULID base32), а не внутренний BIGINT.
        $this->assertIsString($first['id']);
        $this->assertSame(26, mb_strlen($first['id']));
        $this->assertArrayNotHasKey('category_id', $first);
        $this->assertArrayNotHasKey('event_id', $first);
        $this->assertArrayNotHasKey('organization_id', $first);
    }

    public function test_the_limit_is_respected(): void
    {
        ['token' => $token, 'user_id' => $userId] = $this->buyer();
        $category = $this->category('cinema-' . Str::random(5));

        $liked = $this->event($category);
        $this->event($category);
        $this->event($category);
        $this->event($category);

        $this->favorite($userId, $liked['event_id']);

        $this->getJson('/api/v1/me/recommendations?limit=2', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_two_buyers_get_different_shelves(): void
    {
        ['token' => $firstToken, 'user_id' => $firstUser] = $this->buyer('buyer1@example.com');
        ['token' => $secondToken, 'user_id' => $secondUser] = $this->buyer('buyer2@example.com');

        $rock = $this->category('rock-' . Str::random(5));
        $kids = $this->category('kids-' . Str::random(5));

        $rockLiked = $this->event($rock);
        $rockSimilar = $this->event($rock);
        $kidsLiked = $this->event($kids);
        $kidsSimilar = $this->event($kids);

        $this->favorite($firstUser, $rockLiked['event_id']);
        $this->favorite($secondUser, $kidsLiked['event_id']);

        $firstShelf = $this->recommendedIds($firstToken);
        $secondShelf = $this->recommendedIds($secondToken);

        // Профиль — личный: подстановка чужого идентификатора невозможна.
        $this->assertContains($rockSimilar['public_id'], $firstShelf);
        $this->assertNotContains($kidsSimilar['public_id'], $firstShelf);
        $this->assertContains($kidsSimilar['public_id'], $secondShelf);
        $this->assertNotContains($rockSimilar['public_id'], $secondShelf);
    }
}
