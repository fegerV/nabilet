<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Удаление площадки из админки: раньше площадку с сеансами нельзя было
 * удалить «вообще» — контроллер делал голый `$venue->delete()`, MySQL
 * отвечала RESTRICT → QueryException → 500, и админ видел лишь красный
 * тост «Не удалось удалить» без причины.
 *
 * Контракт после правки (`VenueController::destroy`):
 *   1. площадка без сеансов удаляется (залы уходят каскадом);
 *   2. площадка с сеансами — 409 `VENUE_HAS_SESSIONS` со счётчиком в
 *      details, площадка остаётся в БД;
 *   3. ошибка приходит в envelope §66, `ApiError.message` фронтенда её
 *      показывает админу как есть.
 */
final class VenueAdminDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

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

    /** Администратор организации — роль `admin` в `user_roles`, не колонка users. */
    private function actingAsAdmin(int $organizationId): void
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

        $this->actingAs(User::query()->findOrFail($userId), 'api');
    }

    /** Площадка с залом (без сеансов) — возвращает [venueId, hallId]. */
    private function seedVenueWithHall(int $organizationId): array
    {
        $now = now()->toDateTimeString();

        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Тестовый клуб',
            'slug' => 'test-venue-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Основной зал',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$venueId, $hallId];
    }

    public function test_deletes_venue_without_sessions_and_cascades_halls(): void
    {
        $organizationId = $this->seedOrganization();
        [$venueId] = $this->seedVenueWithHall($organizationId);
        $this->actingAsAdmin($organizationId);

        $publicId = DB::table('venues')->where('id', $venueId)->value('public_id');

        $this->deleteJson('/api/v1/venues/' . $publicId)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('venues', ['id' => $venueId], 'mysql');
        // `halls.venue_id` CASCADE: зал уходит вместе с площадкой — так и
        // говорит админу текст подтверждения на клиенте.
        $this->assertDatabaseMissing('halls', ['venue_id' => $venueId], 'mysql');
    }

    public function test_refuses_to_delete_venue_with_sessions(): void
    {
        // Полный цепочный фикстурный комплект: org → event → venue → hall →
        // schema_version → session → inventory_item.
        [$organizationId, , $sessionId] = $this->seedSellableSeat(1);

        $venueId = (int) DB::table('sessions')->where('id', $sessionId)->value('venue_id');
        $publicId = DB::table('venues')->where('id', $venueId)->value('public_id');

        $this->actingAsAdmin($organizationId);

        $this->deleteJson('/api/v1/venues/' . $publicId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VENUE_HAS_SESSIONS')
            ->assertJsonPath('error.details.sessions_count', 1);

        // Площадка на месте: RESTRICT не даёт удалить, но и мы сами не удаляем.
        $this->assertDatabaseHas('venues', ['id' => $venueId], 'mysql');
        $this->assertDatabaseHas('sessions', ['id' => $sessionId], 'mysql');
    }
}
