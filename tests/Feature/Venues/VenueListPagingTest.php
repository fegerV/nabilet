<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Database\Seeders\SurgutVenuesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кламп размера страницы в публичном каталоге площадок.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ТЕСТ. `VenueController::index()` читал `per_page` из запроса
 * и отдавал его в пагинатор БЕЗ клампа — `?per_page=1000000` заставлял MySQL
 * прочитать всю таблицу. Это тот же дефект, что уже был закрыт в остальных
 * списках (`MediaController`, заказы, платежи, чекин), но в каталоге площадок
 * клампа не было вовсе. Формула клампа живёт в одном владельце
 * (`Nabilet\Core\Support\Paging`), его арифметика покрыта `tests/Unit/PagingTest`,
 * а этот тест доказывает, что ВЫЗОВ владельца действительно стоит в контроллере:
 * без него `?per_page=1000000` снова ушёл бы в пагинатор как есть.
 *
 * Форма ответа — как у остальных списков: сам пагинатор в `data`, мета в `meta`.
 */
class VenueListPagingTest extends TestCase
{
    use RefreshDatabase;

    /** Потолок: без клампа `?per_page=1000000` — это выборка всей таблицы. */
    public function test_per_page_above_the_ceiling_is_clamped(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $response = $this->getJson('/api/v1/venues?per_page=1000000')->assertOk();

        $this->assertSame(100, $response->json('meta.per_page'));
    }

    /** Пол: неположительный `per_page` не должен ломать пагинатор. */
    public function test_non_positive_per_page_falls_back_to_one(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $response = $this->getJson('/api/v1/venues?per_page=0')->assertOk();

        $this->assertSame(1, $response->json('meta.per_page'));
    }

    /** Значение внутри диапазона уважается: кламп не должен «схлопывать» всё в потолок. */
    public function test_a_page_size_inside_the_range_is_honoured(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $response = $this->getJson('/api/v1/venues?per_page=5')->assertOk();

        $this->assertSame(5, $response->json('meta.per_page'));
        $this->assertCount(5, $response->json('data.data'));
        $this->assertSame(count(SurgutVenuesSeeder::venues()), $response->json('meta.total'));
    }
}
