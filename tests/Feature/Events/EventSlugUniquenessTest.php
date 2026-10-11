<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Глобальная уникальность адреса мероприятия (SEO-аудит P-03).
 *
 * ДЕФЕКТ. `slug` был уникален только внутри организации
 * (`UNIQUE KEY uq_events_org_slug (organization_id, slug)`), а публичный адрес
 * организации не содержит: `/event/{slug}/{publicId}`. Резолв тенанта при этом
 * устроен как «первая организация по `id`». Пока организация одна, разницы
 * нет; со второй —
 *
 *   • один адрес носят два события из разных организаций, и открывается то,
 *     чья организация оказалась первой по `id`, то есть произвольное;
 *   • canonical двух страниц неразличим, и обе претендуют на один URL;
 *   • `redirects.source` объявлен глобально уникальным, поэтому переименование
 *     адреса в одной организации затирало бы редирект другой.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Два следствия исправления, и оба — поведенческие, а не
 * «индекс существует»:
 *
 *   1. Событие ВТОРОЙ организации открывается по своему публичному адресу.
 *      Это прямая регрессия: старый запрос фильтровал по первой организации и
 *      отдавал 404 на живой адрес. Тест падал бы до исправления.
 *   2. Занятый адрес нельзя занять второй раз — ни при создании, ни при
 *      обновлении, и отказ приходит как 422 с указанием поля, а не как 500 от
 *      `QueryException` на уникальном индексе.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В `tests/Unit`. Проверяются живой HTTP-контур, глобальный
 * middleware и настоящий уникальный индекс MySQL. `tests/run.php` пропускает
 * Feature-тесты, поэтому файл исполняется `php vendor/bin/phpunit`.
 */
final class EventSlugUniquenessTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── Публичный адрес события из НЕ первой организации ────────────────────

    /**
     * Страница события второй организации отвечает 200.
     *
     * ЭТО ГЛАВНАЯ РЕГРЕССИЯ P-03. `seedSellableSeat()` каждый раз создаёт новую
     * организацию, поэтому второе событие заведомо принадлежит не той, которую
     * выберет фолбэк «первая по `id`». Пока поиск скоупился по организации,
     * страница отдавала 404 — при том что адрес живой и уникальный.
     */
    public function test_the_event_page_opens_for_a_non_first_organization(): void
    {
        // Первая организация — та, которую выберет фолбэк «первая по id».
        $this->seedSellableSeat(3);

        // Вторая организация: её событие и есть проверяемое.
        [$secondOrgId, $secondEventId] = $this->seedSellableSeatInNewOrganization();

        $row = DB::table('events')->where('id', $secondEventId)->first();

        $firstOrgId = (int) DB::table('organizations')->orderBy('id')->value('id');

        $this->assertNotSame(
            $firstOrgId,
            $secondOrgId,
            'фикстура должна была создать событие во ВТОРОЙ организации'
        );

        $response = $this->get(route('events.show', [
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
        ]));

        $response->assertStatus(200);
        $this->assertStringContainsString(
            '<title>Тестовое событие',
            (string) $response->getContent()
        );
    }

    /** Витринный API отдаёт событие второй организации по slug. */
    public function test_the_storefront_api_resolves_a_non_first_organization(): void
    {
        $this->seedSellableSeat(3);

        [, $secondEventId] = $this->seedSellableSeatInNewOrganization();

        $slug = (string) DB::table('events')->where('id', $secondEventId)->value('slug');

        $this->getJson('/api/v1/events/by-slug/' . $slug)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $secondEventId);
    }

    // ── Занятый адрес ──────────────────────────────────────────────────────

    /**
     * Создание второго события с занятым адресом — 422, а не 500.
     *
     * Без правила валидации запрос дошёл бы до `INSERT` и упал
     * `QueryException` на `uq_events_slug`: администратор увидел бы «серверную
     * ошибку» вместо указания на поле, которое нужно поправить.
     */
    public function test_a_taken_slug_is_rejected_on_create_with_a_validation_error(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $this->createEvent($orgId, ['slug' => 'novyj-god-v-kremle']);

        $response = $this->postJson('/api/v1/events', [
            'organization_id' => $orgId,
            'title' => 'Другой концерт',
            'slug' => 'novyj-god-v-kremle',
        ]);

        $this->assertSlugRejected($response);
    }

    /**
     * Занятый адрес ДРУГОЙ организации — тоже 422.
     *
     * Это и есть суть P-03: адрес глобален, потому что глобален URL. Раньше
     * правило (в лице индекса `organization_id + slug`) такой случай пропускало.
     */
    public function test_a_slug_taken_by_another_organization_is_rejected(): void
    {
        $firstOrgId = $this->seedOrganization();
        $this->actingAsAdmin($firstOrgId);
        $this->createEvent($firstOrgId, ['slug' => 'novyj-god-v-kremle']);

        $secondOrgId = $this->seedOrganization();
        $this->actingAsAdmin($secondOrgId);

        $response = $this->postJson('/api/v1/events', [
            'organization_id' => $secondOrgId,
            'title' => 'Концерт второй организации',
            'slug' => 'novyj-god-v-kremle',
        ]);

        $this->assertSlugRejected($response);
    }

    /** Свободный адрес по-прежнему проходит. */
    public function test_a_free_slug_is_accepted(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $this->postJson('/api/v1/events', [
            'organization_id' => $orgId,
            'title' => 'Концерт',
            'slug' => 'svobodnyj-adres-' . Str::random(5),
        ])->assertStatus(201);
    }

    /**
     * Сохранение события БЕЗ смены адреса не падает на собственном значении.
     *
     * Форма админки отправляет `slug` всегда, даже когда его не меняли. Без
     * `Rule::unique(...)->ignore($eventId)` обычная правка описания отдавала бы
     * 422 «адрес занят» — и починить это, не сменив адрес, было бы нельзя.
     */
    public function test_updating_an_event_keeps_its_own_slug(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $eventId = $this->createEvent($orgId, ['slug' => 'svoj-adres']);

        $this->putJson('/api/v1/events/' . $eventId, [
            'slug' => 'svoj-adres',
            'title' => 'Новое название',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'svoj-adres');
    }

    /** Перевести событие на адрес, занятый другим событием, нельзя. */
    public function test_updating_an_event_onto_a_taken_slug_is_rejected(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $this->createEvent($orgId, ['slug' => 'zanyato']);
        $eventId = $this->createEvent($orgId, ['slug' => 'svobodno']);

        $this->assertSlugRejected(
            $this->putJson('/api/v1/events/' . $eventId, ['slug' => 'zanyato'])
        );
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * 422 с ошибкой именно по полю `slug`.
     *
     * Конверт §66, а не формат Laravel: ответ выглядит как
     * `{"error":{"code":"VALIDATION_ERROR","details":{"fields":{"slug":[…]}}}}`.
     * Поэтому `assertJsonValidationErrors()` здесь не подходит — он ищет ключ
     * `errors`, которого в этом контракте нет, и «отсутствие ошибок» в нём
     * означало бы ровно противоположное тому, что проверяется.
     */
    private function assertSlugRejected(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertArrayHasKey(
            'slug',
            (array) $response->json('error.details.fields'),
            'конверт §66 не сообщил, что конфликтует именно поле slug'
        );
    }

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт ' . Str::random(4),
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Событие с сеансом и инвентарём в СВОЕЙ (новой) организации.
     *
     * @return array{0: int, 1: int} [organizationId, eventId]
     */
    private function seedSellableSeatInNewOrganization(): array
    {
        [$orgId, $eventId] = $this->seedSellableSeat(3);

        return [(int) $orgId, (int) $eventId];
    }

    /**
     * Администратор организации.
     *
     * Роль читается из `user_roles`: именно её смотрит `StaffRole::isStaff()`,
     * которым пользуется middleware `admin`.
     */
    private function actingAsAdmin(int $organizationId): User
    {
        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createEvent(int $organizationId, array $extra = []): int
    {
        $response = $this->postJson('/api/v1/events', array_merge([
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-' . Str::random(8),
        ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }
}
