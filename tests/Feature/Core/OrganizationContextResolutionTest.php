<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Core\Support\UserOrganization;
use Tests\TestCase;

/**
 * `ResolveOrganizationContext` — откуда берётся организация запроса.
 *
 * ЧТО БЫЛО СЛОМАНО (две независимые причины, обе давали 500):
 *
 *  1. Middleware читал `users.organization_id`, а такой колонки в схеме НЕТ:
 *     членство сотрудника живёт в `user_organization`, и установщик привязывает
 *     админа именно туда (`InstallerController::bootstrapOrganization()`).
 *     Контекст у сотрудника не заполнялся никогда.
 *
 *  2. Middleware стоит в ГЛОБАЛЬНОМ стеке, то есть раньше маршрутного
 *     `auth:api`. `$request->user()` смотрит в guard по умолчанию (`web`), а
 *     публичный API авторизуется bearer-токеном через guard `api`. Даже с
 *     валидным токеном пользователь был не виден.
 *
 *  3. `OrganizationContext` не был singleton'ом: `NabiletServiceProvider` его
 *     так объявляет, но сам не зарегистрирован (он бутит все модули и вернул бы
 *     маршруты вне /api/v1). Контейнер отдавал каждому потребителю новый
 *     экземпляр — middleware писал в свой, контроллер читал пустой.
 *
 * Вместе это означало: любой tenant-scoped админский путь отвечает 500
 * `TenantContextMissingError`. Здесь проверяется, что организация находится по
 * членству и что чужие данные при этом не видны.
 */
final class OrganizationContextResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private function promoCode(int $organizationId, string $code): void
    {
        DB::table('promo_codes')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'code' => $code,
            'discount_type' => 'percent',
            'value_amount' => 0,
            'value_percent' => 10,
            'currency' => 'RUB',
            'scope' => 'all',
            'min_order_amount' => 0,
            'per_user_limit' => 1,
            'redemptions_count' => 0,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /** Сотрудник организации: пользователь + членство в `user_organization`. */
    private function staffOf(int $organizationId, string $email): User
    {
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'password' => bcrypt('Secret123456!'),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // `user_organization.role_id` — внешний ключ на `roles`, а сиды в тестах
        // не выполняются, поэтому роль создаём сами (идемпотентно).
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
                'description' => 'Полный доступ',
            ]));

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        return User::query()->findOrFail($userId);
    }

    public function test_a_staff_member_resolves_the_organization_from_membership(): void
    {
        $organizationId = $this->organization('Сургут-Концерт');
        $this->promoCode($organizationId, 'OWN10');

        $staff = $this->staffOf($organizationId, 'staff@nabilet.local');

        // `actingAs($user, 'api')` ставит пользователя в bearer-guard — тот же,
        // что middleware теперь опрашивает явно.
        $response = $this->actingAs($staff, 'api')->getJson('/api/v1/promo-codes');

        $response->assertOk();
        $response->assertJsonPath('data.0.code', 'OWN10');
    }

    public function test_a_staff_member_does_not_see_another_organization_codes(): void
    {
        $own = $this->organization('Своя организация');
        $foreign = $this->organization('Чужая организация');

        $this->promoCode($own, 'MINE10');
        $this->promoCode($foreign, 'THEIRS10');

        $staff = $this->staffOf($own, 'staff2@nabilet.local');

        $response = $this->actingAs($staff, 'api')->getJson('/api/v1/promo-codes');

        $response->assertOk();
        $codes = array_column($response->json('data'), 'code');

        $this->assertContains('MINE10', $codes);
        $this->assertNotContains('THEIRS10', $codes);
    }

    /**
     * Сотрудник с ролью, но БЕЗ членства не создаёт событие в чужой организации.
     *
     * ДЕФЕКТ, КОТОРЫЙ ЗДЕСЬ ЗАКРЫТ. `EventController::store()` при отсутствии
     * `organization_id` в payload брал организацию пользователя, а если её не
     * было — ПЕРВУЮ ОРГАНИЗАЦИЮ В ТАБЛИЦЕ. То есть сотрудник с ролью `admin`
     * (её даёт `user_roles`, и middleware `admin` её проверяет) и без строки в
     * `user_organization` молча создавал мероприятие в чужом тенанте: его
     * видели и правили сотрудники той организации, оно уходило на их витрину и
     * в их карту сайта. Ошибки не возникало нигде — `organization_id` NOT NULL,
     * значение валидное, ответ 201.
     *
     * Роль и членство — РАЗНЫЕ таблицы, и ровно это и делало дефект возможным:
     * `EnsureAdminRole` смотрит в `user_roles`, а организацию запроса даёт
     * `user_organization`. Проверка здесь идёт через реальный HTTP, потому что
     * проверяется весь диапазон «роль есть → доступ есть → организация есть».
     */
    public function test_a_staff_member_without_membership_cannot_file_an_event_into_a_foreign_tenant(): void
    {
        $foreign = $this->organization('Чужая организация');
        $this->promoCode($foreign, 'THEIRS10');

        // Роль есть, членства нет: `admin` пускает мимо middleware'а.
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'role-but-no-membership@nabilet.local',
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]));

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $foreign,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        $staff = User::query()->findOrFail($userId);

        $response = $this->actingAs($staff, 'api')->postJson('/api/v1/events', [
            'title' => 'Событие без арендатора',
            'slug' => 'sobytie-bez-arendatora',
        ]);

        $response->assertStatus(422);

        // И главное — строки в `events` нет вовсе. До правки здесь был 201 и
        // мероприятие, принадлежащее `$foreign`.
        $this->assertSame(0, DB::table('events')->count());
    }

    /**
     * Сотрудник с двумя членствами получает одну и ту же организацию везде.
     *
     * `->first()` без сортировки возвращает ту строку, которую отдаст MySQL, то
     * есть разную в разные запросы. `UserOrganization` и `ResolveOrganizationContext`
     * сортируют по id, поэтому middleware и контроллер не могут разойтись в
     * рамках одного запроса. Проверяется на витрине: она читает организацию
     * запроса и отдаёт её конфиг.
     */
    public function test_a_staff_member_with_two_memberships_gets_one_deterministic_organization(): void
    {
        $first = $this->organization('Первая организация');
        $second = $this->organization('Вторая организация');

        $this->promoCode($first, 'FIRST10');
        $this->promoCode($second, 'SECOND10');

        $staff = $this->staffOf($first, 'two-memberships@nabilet.local');

        // Второе членство — тем же пользователем, другой организации.
        $roleId = (int) DB::table('roles')->where('slug', 'admin')->value('id');
        DB::table('user_organization')->insert([
            'user_id' => $staff->getKey(),
            'organization_id' => $second,
            'role_id' => $roleId,
            'created_at' => now()->toDateTimeString(),
        ]);

        // Сам помощник выбирает по id, а не по порядку строк.
        $this->assertSame($first, UserOrganization::id($staff));

        // И middleware выбирает ТУ ЖЕ: `/promo-codes` скоупится организацией
        // из контекста, то есть из `ResolveOrganizationContext`. До правки оба
        // места звали `->first()` без сортировки, и в рамках одного запроса
        // могли разойтись — контроллер правил бы одной организации, а список
        // показывал другой.
        $response = $this->actingAs($staff, 'api')->getJson('/api/v1/promo-codes');

        $response->assertOk();
        $this->assertSame(['FIRST10'], array_column($response->json('data'), 'code'));
    }

    public function test_a_user_without_membership_gets_no_foreign_organization_data(): void
    {
        $organizationId = $this->organization('Сургут-Концерт');
        $this->promoCode($organizationId, 'SECRET10');

        // Покупатель: аккаунт есть, членства в организации нет.
        $customerId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'buyer@example.com',
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $customer = User::query()->findOrFail($customerId);

        $response = $this->actingAs($customer, 'api')->getJson('/api/v1/promo-codes');

        // Контекста нет — и подставлять «первую организацию» нельзя: это была бы
        // утечка чужих кодов. Ответ обязан быть отказом, а не списком.
        $this->assertNotSame(200, $response->status());
        $this->assertStringNotContainsString('SECRET10', $response->getContent());
    }
}
