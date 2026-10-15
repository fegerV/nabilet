<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Database\Seeders\NabiletAdminSeeder;
use Database\Seeders\SurgutVenuesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Стартовый каталог площадок Сургута (`Database\Seeders\SurgutVenuesSeeder`).
 *
 * Сидер существует ради одного обещания: «сразу после установки на хостинг
 * площадки уже есть». Тесты проверяют именно его — что обычный `db:seed --force`
 * (единственная команда сидирования, которую запускает установщик) доводит
 * каталог до конца, что каталог ложится в организацию установки, что повторный
 * запуск ничего не дублирует и не затирает правки администратора, и что без
 * организации сидер молчит, а не создаёт арендатора сам.
 *
 * Отдельно проверяется связка с витриной: площадки обязаны быть видны публичным
 * каталогом `GET /api/v1/venues` без авторизации. Иначе «сидер отработал» и
 * «покупатель видит площадку» — два разных утверждения, и второе важнее.
 */
class SurgutVenuesSeederTest extends TestCase
{
    use RefreshDatabase;

    /* ── Установка: обычный `db:seed --force` доводит каталог до конца ─────── */

    public function test_a_plain_db_seed_brings_up_the_surgut_catalog(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $expected = count(SurgutVenuesSeeder::venues());

        $this->assertSame($expected, DB::table('venues')->count());
        $this->assertSame(
            $expected,
            DB::table('venues')->where('status', 'active')->count(),
            'все стартовые площадки должны быть активны — иначе их не видно в каталоге',
        );
    }

    public function test_the_catalog_lands_in_the_organization_of_the_admin(): void
    {
        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true])
            ->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => SurgutVenuesSeeder::class, '--force' => true])
            ->assertExitCode(0);

        // Та же связка, которой пользуется установщик: `bootstrapOrganization()`
        // создаёт организацию и пишет админа в `user_organization`. Сидер обязан
        // выбрать ровно её — площадка в чужом тенанте была бы невидима владельцу.
        $adminOrganization = DB::table('user_organization')
            ->join('roles', 'roles.id', '=', 'user_organization.role_id')
            ->where('roles.slug', 'admin')
            ->value('user_organization.organization_id');

        $this->assertNotNull($adminOrganization);

        $this->assertSame(
            count(SurgutVenuesSeeder::venues()),
            DB::table('venues')->where('organization_id', (int) $adminOrganization)->count(),
        );
    }

    public function test_the_seeded_catalog_is_visible_in_the_public_catalog(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $response = $this->getJson('/api/v1/venues?per_page=100')->assertOk();

        // `VenueController::index()` отдаёт сам пагинатор в `data`, поэтому
        // список лежит в `data.data`.
        $rows = $response->json('data.data') ?? [];

        $this->assertCount(count(SurgutVenuesSeeder::venues()), $rows);
        $this->assertContains('Сургутская филармония', array_column($rows, 'name'));
        $this->assertContains('Сургут', array_column($rows, 'city'));
    }

    /**
     * Ровно тот сценарий, ради которого сидер написан: установка на хостинг.
     *
     * В production `NabiletAdminSeeder` демо-данные не создаёт (аудит
     * установщика B-2), поэтому организацию создаёт установщик —
     * `InstallerController::bootstrapOrganization()`, шаг 5, ДО `db:seed`.
     * Здесь этот шаг воспроизводится вручную: сидер обязан найти эту
     * организацию и наполнить каталог без какой-либо дополнительной команды.
     */
    public function test_a_production_install_gets_the_catalog_without_demo_data(): void
    {
        $this->app['env'] = 'production';

        // Роли сидируются всегда — установщик вызывает `db:seed` после миграций.
        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $organizationId = DB::table('organizations')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'name' => 'Касса Сургута',
            'slug' => 'kassa-surguta',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'email' => 'owner@example.com',
            'password' => bcrypt('super-secret-password'),
            'first_name' => 'Administrator',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => DB::table('roles')->where('slug', 'admin')->value('id'),
            'created_at' => now(),
        ]);

        // То же, что делает установщик на шаге 7.
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $expected = count(SurgutVenuesSeeder::venues());

        $this->assertSame($expected, DB::table('venues')->count());
        $this->assertSame(
            $expected,
            DB::table('venues')->where('organization_id', $organizationId)->count(),
        );
        $this->assertSame(0, DB::table('users')->where('email', 'admin@nabilet.local')->count());
    }

    /* ── Идемпотентность: повторный сид безопасен ─────────────────────────── */

    public function test_reseeding_does_not_duplicate_and_keeps_manual_edits(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $expected = count(SurgutVenuesSeeder::venues());

        // Правка администратора: именно её повторный `db:seed --force` (его
        // запускает и установщик, и деплой) обязан оставить в покое.
        DB::table('venues')->where('slug', 'surgutskaya-filarmoniya')->update([
            'name' => 'Филармония (переименована администратором)',
            'address' => 'ул. Энгельса, 18, вход со двора',
        ]);

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($expected, DB::table('venues')->count());

        $venue = DB::table('venues')->where('slug', 'surgutskaya-filarmoniya')->first();

        $this->assertNotNull($venue);
        $this->assertSame('Филармония (переименована администратором)', $venue->name);
        $this->assertSame('ул. Энгельса, 18, вход со двора', $venue->address);
    }

    /* ── Fail-soft: без организации сидер не выдумывает арендатора ─────────── */

    public function test_it_skips_when_there_is_no_organization(): void
    {
        // `RefreshDatabase` даёт пустую базу: миграции применены, данных нет.
        $this->assertSame(0, DB::table('organizations')->count());

        $this->artisan('db:seed', ['--class' => SurgutVenuesSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('venues')->count());
        $this->assertSame(
            0,
            DB::table('organizations')->count(),
            'сидер не должен создавать организацию: администрировать её было бы некому',
        );
    }

    public function test_the_configured_organization_wins_over_the_admin_one(): void
    {
        $this->artisan('db:seed', ['--class' => NabiletAdminSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $otherId = DB::table('organizations')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::ulid()->toBase32(),
            'name' => 'Другая организация',
            'slug' => 'drugaya-organizaciya',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['nabilet.seed_venues_organization_id' => $otherId]);

        $this->artisan('db:seed', ['--class' => SurgutVenuesSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(
            count(SurgutVenuesSeeder::venues()),
            DB::table('venues')->where('organization_id', $otherId)->count(),
        );
    }

    /* ── Целостность самого каталога ──────────────────────────────────────── */

    public function test_the_catalog_itself_is_consistent(): void
    {
        $venues = SurgutVenuesSeeder::venues();

        $this->assertCount(39, $venues, 'каталог площадок Сургута изменился — проверьте, что это осознанно');

        $slugs = [];
        $names = [];

        foreach ($venues as $venue) {
            $this->assertNotSame('', trim($venue['name']), 'у площадки нет названия');
            $this->assertNotSame('', trim($venue['address']), 'у площадки нет адреса: ' . $venue['name']);
            $this->assertNotSame('', trim($venue['city']), 'у площадки нет города: ' . $venue['name']);
            $this->assertNotSame('', trim($venue['description']), 'у площадки нет описания: ' . $venue['name']);

            // Слаг — публичный адрес `/venue/{slug}`. Он обязан быть в ASCII,
            // в нижнем регистре и без пробелов: транслитерация уже выполнена
            // и зафиксирована в сидере.
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $venue['slug'], $venue['name']);

            $slugs[] = $venue['slug'];
            $names[] = $venue['name'];
        }

        $this->assertSame($slugs, array_unique($slugs), 'слаги площадок должны быть уникальны');
        $this->assertSame($names, array_unique($names), 'названия площадок должны быть уникальны');
    }

    public function test_seeded_rows_match_the_venues_table_contract(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $rows = DB::table('venues')->get();

        foreach ($rows as $row) {
            // `public_id` — char(26), ULID в base32. Колонка NOT NULL и служит
            // ключом маршрута (`Venue::getRouteKeyName()`).
            $this->assertSame(26, strlen((string) $row->public_id), (string) $row->slug);
            $this->assertSame('RU', $row->country, (string) $row->slug);
            $this->assertSame(SurgutVenuesSeeder::REGION, $row->region, (string) $row->slug);
        }

        // Городские площадки — «Сургут»; площадки Сургутского района сохраняют
        // свой населённый пункт, иначе они попадали бы в городскую страницу
        // Сургута с неверным `addressLocality` в разметке.
        $this->assertSame(
            ['Барсово', 'Белый Яр', 'Сургут'],
            DB::table('venues')->distinct()->orderBy('city')->pluck('city')->all(),
        );
    }
}
