<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Переводы площадки (контракт openapi.yaml:2384-2424, тег Translations).
 *
 * До этого шага пути контракта отвечали 404: колонки `venue_translations`
 * читались только eager-загрузкой в `VenueController::show`, а записывать их
 * было нечем. Контракт полосатый: список — витрина (без auth), upsert —
 * под `auth:api` + `admin` организации площадки.
 *
 * ИЗОЛЯЦИЯ. `Venue` — скоупнутая модель (тридцать девятая): binding в
 * admin-пути отсекает чужую площадку 404'ом ещё до контроллера, и тест
 * фиксирует именно это поведение (чужой public_id неотличим от
 * несуществующего — IDOR/BOLA).
 */
final class VenueTranslationsTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    public function test_public_list_returns_translations_of_a_venue(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId, $venueId] = $this->seedVenue($organizationId);

        DB::table('venue_translations')->insert([
            ['venue_id' => $venueId, 'organization_id' => $organizationId, 'locale' => 'ru', 'name' => 'Клуб «Сура»'],
            ['venue_id' => $venueId, 'organization_id' => $organizationId, 'locale' => 'en', 'name' => 'Sura Club'],
        ]);

        $response = $this->getJson("/api/v1/venues/{$venuePublicId}/translations")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Список отсортирован по locale в контроллере — порядок ответа стабилен.
        $locales = collect($response->json('data'))->pluck('locale')->all();
        $this->assertSame(['en', 'ru'], $locales);
    }

    public function test_public_list_answers_404_for_unknown_venue(): void
    {
        $this->getJson('/api/v1/venues/' . Str::ulid()->toBase32() . '/translations')
            ->assertNotFound();
    }

    public function test_upsert_creates_then_replaces_the_same_row(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId] = $this->seedVenue($organizationId);
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/en", [
            'name' => 'Sura Club',
            'address' => '1 Embankment',
            'seo_description' => 'Sport venue',
        ])->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.name', 'Sura Club');

        $this->assertSame(1, DB::table('venue_translations')->count());

        // Замена: PUT фиксирует полный образ перевода — поле, не присланное
        // в запросе, стирается, письмо идемпотентно.
        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/en", [
            'name' => 'Sura Sports Club',
        ])->assertOk()->assertJsonPath('data.name', 'Sura Sports Club');

        $row = DB::table('venue_translations')->first();
        $this->assertSame('Sura Sports Club', $row->name);
        $this->assertSame(null, $row->address, 'не присланные nullable-поля стираются');
        $this->assertSame($organizationId, (int) $row->organization_id, 'тенант наследуется от площадки');
    }

    public function test_upsert_requires_authentication(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId] = $this->seedVenue($organizationId);

        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/en", ['name' => 'X'])
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('venue_translations')->count());
    }

    public function test_upsert_answers_404_for_foreign_and_unknown_venue(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId] = $this->seedVenue($organizationId);

        $otherOrganizationId = $this->seedOrganization('Соседняя организация');
        [$foreignPublicId] = $this->seedVenue($otherOrganizationId, 'Foreign club');

        $this->actingAsStaff($organizationId);

        // Чужая площадка неотличима от несуществующей.
        $this->putJson("/api/v1/venues/{$foreignPublicId}/translations/en", ['name' => 'Hacked'])
            ->assertNotFound();
        $this->putJson('/api/v1/venues/' . Str::ulid()->toBase32() . '/translations/en', ['name' => 'Ghost'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('venue_translations')->count());
    }

    public function test_upsert_answers_422_for_unsupported_locale(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId] = $this->seedVenue($organizationId);
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/ru_RU", ['name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.locale.0', 'The locale must be a language tag like "ru", "en" or "pt-br".');
    }

    public function test_upsert_normalizes_locale_case(): void
    {
        $organizationId = $this->seedOrganization();
        [$venuePublicId] = $this->seedVenue($organizationId);
        $this->actingAsStaff($organizationId);

        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/EN", ['name' => 'Sura Club'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');

        // MySQL сравнивает VARCHAR в *_ci collation без регистра: без
        // нормализации «EN» и «en» заняли бы одно место в uq_venue_translations,
        // оставаясь двумя ключами для клиента.
        $this->putJson("/api/v1/venues/{$venuePublicId}/translations/en", ['name' => 'Sura 2'])
            ->assertOk();

        $this->assertSame(1, DB::table('venue_translations')->count());
    }

    private function seedOrganization(string $name = 'Сургут-Концерт'): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{0: string, 1: int} [venue public_id, venue id] */
    private function seedVenue(int $organizationId, string $name = 'Клуб «Сура»'): array
    {
        $now = now()->toDateTimeString();
        $publicId = (string) Str::ulid()->toBase32();

        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => $publicId,
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => 'venue-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$publicId, $venueId];
    }
}
