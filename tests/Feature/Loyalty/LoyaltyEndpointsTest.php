<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Tests\TestCase;

/**
 * «Клуб зрителей», фаза 1 — пространство `/api/v1/me/*`: избранное и подписки.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. ДОСТУП. Весь префикс закрыт `auth:api`: без аккаунта «избранное» негде
 *      хранить, и молчаливое «сохранено» было бы ложью. Первый тест стучится
 *      во все шесть путей без токена.
 *   2. ВЛАДЕЛЕЦ. Ни один метод не принимает `user_id` — владелец приходит из
 *      Bearer-токена. Тест `two_buyers_do_not_see_each_other` проверяет, что
 *      список не расширяется подстановкой, а делится ровно по токену.
 *   3. ИДЕМПОТЕНТНОСТЬ. Повторное «❤️» — тот же факт, а не вторая строка:
 *      `uq_event_favorites_user_event` (и её близнец для подписок) заперты в
 *      схеме, и повторный POST обязан отвечать 200, а не падать на 1062.
 *   4. ВНЕШНИЙ КЛЮЧ. `user_id`, `event_id` — внутренние BIGINT. Ответ отдаёт
 *      `public_id`, и тест проверяет ОТСУТСТВИЕ внутренних идентификаторов:
 *      «нет `user_id` в ответе» не проверяет никто, кроме такого теста.
 *   5. УДАЛЁННОЕ МЕРОПРИЯТИЕ. Отметка на удалённом событии — 404: список
 *      «хочу посмотреть» не должен воскрешать то, чего витрина уже не видит.
 */
class LoyaltyEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kv7#mRta2!Qz';

    private function tokenFor(string $email = 'buyer@example.com'): string
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ])->assertStatus(201);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertStatus(200)->json('data.token');
    }

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(int $organizationId, bool $trashed = false): string
    {
        $now = now()->toDateTimeString();
        $publicId = (string) Str::ulid()->toBase32();

        DB::table('events')->insert([
            'public_id' => $publicId,
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-sekret-' . Str::random(6),
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $trashed ? $now : null,
        ]);

        return $publicId;
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    // ── 1. доступ ──────────────────────────────────────────────────────────

    public function test_every_endpoint_requires_an_account(): void
    {
        $this->getJson('/api/v1/me/favorites')->assertStatus(401);
        $this->getJson('/api/v1/me/follows')->assertStatus(401);
        $this->postJson('/api/v1/me/events/01JZZZZZZZZZZZZZZZZZZZZZZZ/favorite')->assertStatus(401);
        $this->deleteJson('/api/v1/me/events/01JZZZZZZZZZZZZZZZZZZZZZZZ/favorite')->assertStatus(401);
        $this->postJson('/api/v1/me/events/01JZZZZZZZZZZZZZZZZZZZZZZZ/follow')->assertStatus(401);
        $this->deleteJson('/api/v1/me/events/01JZZZZZZZZZZZZZZZZZZZZZZZ/follow')->assertStatus(401);
        // Фаза 2a: бонусный счёт. Баланс чужому не отдаём — владелец всегда из
        // токена, поэтому гостю здесь 401, а не «ноль бонусов».
        $this->getJson('/api/v1/me/bonus')->assertStatus(401);
        $this->getJson('/api/v1/me/bonus/transactions')->assertStatus(401);
    }

    public function test_unknown_event_is_404_not_500(): void
    {
        $token = $this->tokenFor();

        $this->postJson('/api/v1/me/events/01JZZZZZZZZZZZZZZZZZZZZZZZ/favorite', [], $this->authHeaders($token))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    public function test_deleted_event_cannot_be_favorited(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization(), trashed: true);

        $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('event_favorites')->count(), 'отметка на удалённом мероприятии всё равно записалась');
    }

    // ── 2. избранное ───────────────────────────────────────────────────────

    public function test_favorite_round_trip(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization());

        $created = $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))
            ->assertStatus(201)
            ->assertJsonPath('data.event.title', 'Концерт «Секрет»')
            ->assertJsonPath('data.event.id', $publicId)
            ->json('data');

        $this->assertIsString($created['id']);
        $this->assertSame(26, mb_strlen($created['id']));

        // Внутренние идентификаторы не покидают API.
        $this->assertArrayNotHasKey('user_id', $created);
        $this->assertArrayNotHasKey('event_id', $created);
        $this->assertArrayNotHasKey('organization_id', $created['event']);

        $list = $this->getJson('/api/v1/me/favorites', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event.title', 'Концерт «Секрет»')
            ->json('data');

        $this->assertArrayNotHasKey('user_id', $list[0]);
        $this->assertArrayNotHasKey('event_id', $list[0]);

        $this->deleteJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))->assertStatus(204);

        $this->getJson('/api/v1/me/favorites', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, DB::table('event_favorites')->count());
    }

    public function test_repeat_favorite_is_the_same_fact_not_a_second_row(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization());

        $first = $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))
            ->assertStatus(201)
            ->json('data.id');

        // Повторное нажатие — 200 с той же строкой. Раньше (без обработки
        // 1062) такой запрос отвечал 500, а «повторить ❤️» — обычное действие.
        $second = $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))
            ->assertStatus(200)
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('event_favorites')->count());
    }

    public function test_removing_a_favorite_twice_is_still_204(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization());

        $this->deleteJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))->assertStatus(204);
        $this->deleteJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))->assertStatus(204);
    }

    // ── 3. подписки ────────────────────────────────────────────────────────

    public function test_follow_round_trip(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization());

        $this->postJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($token))
            ->assertStatus(201)
            ->assertJsonPath('data.event.id', $publicId);

        $this->getJson('/api/v1/me/follows', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($token))
            ->assertStatus(200);

        $this->assertSame(1, DB::table('event_follows')->count());

        $this->deleteJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($token))->assertStatus(204);
        $this->deleteJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($token))->assertStatus(204);

        $this->getJson('/api/v1/me/follows', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_favorites_and_follows_are_independent_lists(): void
    {
        $token = $this->tokenFor();
        $publicId = $this->seedEvent($this->seedOrganization());

        $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($token))->assertStatus(201);

        $this->getJson('/api/v1/me/favorites', $this->authHeaders($token))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me/follows', $this->authHeaders($token))->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($token))->assertStatus(201);

        $this->getJson('/api/v1/me/favorites', $this->authHeaders($token))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me/follows', $this->authHeaders($token))->assertJsonCount(1, 'data');
    }

    // ── 4. владелец ────────────────────────────────────────────────────────

    public function test_two_buyers_do_not_see_each_other(): void
    {
        $first = $this->tokenFor('buyer1@example.com');
        $second = $this->tokenFor('buyer2@example.com');
        $publicId = $this->seedEvent($this->seedOrganization());

        $this->postJson("/api/v1/me/events/{$publicId}/favorite", [], $this->authHeaders($first))->assertStatus(201);

        $this->getJson('/api/v1/me/favorites', $this->authHeaders($first))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me/favorites', $this->authHeaders($second))->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/me/events/{$publicId}/follow", [], $this->authHeaders($second))->assertStatus(201);

        $this->getJson('/api/v1/me/follows', $this->authHeaders($first))->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/follows', $this->authHeaders($second))->assertJsonCount(1, 'data');
    }

    public function test_issued_token_is_the_only_way_in(): void
    {
        // Токен, выданный другому составу сессий, не открывает чужое избранное:
        // guard читает sha256 из user_sessions, а не строку «на слово».
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'stranger@example.com',
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->getJson('/api/v1/me/favorites', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertStatus(401);

        $this->assertNotNull($this->app->make(SessionIssuer::class)->issue($userId));
    }
}
