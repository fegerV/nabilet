<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * `LIKE`-фильтры против символов шаблона (аудит S-11).
 *
 * ДЕФЕКТ. Из трёх `LIKE`-фильтров экранирование было ровно в одном, и то через
 * `addcslashes`, который не сообщает СУБД, какой символ считать экранирующим:
 *
 *   - `OrderRepository`          — `addcslashes` (единственный «сделанный»);
 *   - `UserRepository::search()` — подстановка ввода в шаблон напрямую;
 *   - `VenueController::index()` — то же.
 *
 * SQL-инъекции не было (значения биндятся через PDO), но `%` и `_` — это
 * СИМВОЛЫ ШАБЛОНА: `city=%` возвращал весь каталог, а `search=a_b` совпадал с
 * `aXb`. Пользователь управлял формой запроса — на публичном каталоге это
 * означало «прочитать все строки вместо индекса», и такой запрос дёшево повторять.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Каждый фильтр вызывается с литеральным `%` и `_` и НЕ должен
 * ничего находить, а с осмысленным префиксом — должен. Второе условие обязательно:
 * «ничего не нашлось» само по себе означало бы просто сломанный фильтр.
 */
class LikeFilterEscapingTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── Публичный каталог площадок ───────────────────────────────────────────

    public function test_the_venue_city_filter_treats_wildcards_as_data(): void
    {
        $organizationId = $this->seedOrganization();

        $this->seedVenue($organizationId, 'Филармония', 'Алматы');
        $this->seedVenue($organizationId, 'Опера', 'Астана');
        // Название с ЛИТЕРАЛЬНЫМ процентом: он доказывает, что фильтр ищет
        // подстроку, а не «совпадает со всем».
        $this->seedVenue($organizationId, 'Театр', 'Алма%ты');

        // `%` — это шаблон, а не данные: ни один город его не содержит.
        $this->assertSame(
            ['Алма%ты'],
            $this->cities('?city=%'),
            'литеральный `%` совпал с любым городом — экранирование не работает'
        );

        // `_` совпадает с одним любым символом — тоже не данные.
        $this->assertSame([], $this->cities('?city=_'), '`_` совпал с любым одиночным символом');

        // Контроль: обычный поиск по подстроке работает.
        $this->assertSame(['Алма%ты', 'Алматы'], $this->sorted($this->cities('?city=Алма')));
        $this->assertSame(['Астана'], $this->cities('?city=Астана'));
    }

    // ── Поиск пользователей (админский) ──────────────────────────────────────

    public function test_the_user_search_treats_wildcards_as_data(): void
    {
        $this->actingAsAdmin();

        $this->seedUser('ivan@example.com', 'Иван', 'Петров');
        $this->seedUser('olga@example.com', 'Ольга', 'Сидорова');

        $this->assertSame(0, $this->searchTotal('?q=%'), '`%` вернул всех пользователей');
        $this->assertSame(0, $this->searchTotal('?q=_'), '`_` совпал с любым одиночным символом');

        // Контроль: поиск по фамилии и по e-mail работает.
        $this->assertSame(1, $this->searchTotal('?q=Петров'));
        $this->assertSame(1, $this->searchTotal('?q=ivan@'));
        $this->assertSame(0, $this->searchTotal('?q=никого'));
    }

    // ── Фильтр заказов по e-mail ─────────────────────────────────────────────

    public function test_the_order_email_filter_treats_wildcards_as_data(): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        $user = $this->seedUser('buyer@example.com', 'Иван', 'Петров');

        $orders = $this->app->make(OrderService::class);

        $orders->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'customer_email' => 'ivan%petrov@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $this->actingAs($user, 'api');

        // Литеральный `%` есть ровно в одном адресе — и он должен найтись,
        // а не «найтись все».
        $this->assertSame(1, $this->orderTotal('?customer_email=%'));
        $this->assertSame(1, $this->orderTotal('?customer_email=ivan%petrov'));
        $this->assertSame(0, $this->orderTotal('?customer_email=ivan_petrov'));
        $this->assertSame(0, $this->orderTotal('?customer_email=никого'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> города из ответа каталога, в порядке ответа. */
    private function cities(string $query): array
    {
        $response = $this->getJson('/api/v1/venues' . $query);

        $response->assertOk();

        // `VenueController::index()` отдаёт сам пагинатор в `data`, поэтому
        // список лежит в `data.data`. Читаем обе формы, чтобы тест не падал от
        // смены формы ответа — его предмет в другом.
        $rows = $response->json('data.data') ?? $response->json('data') ?? [];

        return array_values(array_filter(array_map(
            static fn (array $venue): string => (string) ($venue['city'] ?? ''),
            array_filter($rows, 'is_array'),
        ), static fn (string $city): bool => $city !== ''));
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function searchTotal(string $query): int
    {
        $response = $this->getJson('/api/v1/users/search' . $query);

        $response->assertOk();

        return (int) $response->json('meta.total');
    }

    private function orderTotal(string $query): int
    {
        $response = $this->getJson('/api/v1/orders' . $query);

        $response->assertOk();

        return (int) $response->json('meta.total');
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тестовая организация',
            'slug' => 'test-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVenue(int $organizationId, string $name, string $city): int
    {
        $now = now();

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => 'venue-' . Str::random(6),
            'city' => $city,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUser(string $email, string $firstName, string $lastName): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt('Kv7#mRta2!Qz'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    /** Администратор: `admin` читает `user_roles`, а не колонку в `users`. */
    private function actingAsAdmin(): User
    {
        $now = now();

        $organizationId = $this->seedOrganization();

        $user = $this->seedUser('admin@example.com', 'Админ', 'Тестовый');

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $this->actingAs($user, 'api');

        return $user;
    }
}
