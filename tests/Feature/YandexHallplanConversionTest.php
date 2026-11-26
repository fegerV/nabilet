<?php

declare(strict_types=1);

namespace Tests\Feature;

use Nabilet\Modules\Venues\Halls\Domain\YandexHallplanConverter;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YandexHallplanConversionTest extends TestCase
{
    public static function halls(): array
    {
        return [['surgu', 512], ['neftyanik', 1073], ['vavilon', 359], ['filarmoniya', 872]];
    }

    #[DataProvider('halls')]
    public function test_real_surgut_geometry_survives_conversion_and_server_validation(string $name, int $count): void
    {
        $source = json_decode(file_get_contents(__DIR__ . '/../Fixtures/hallplans/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new YandexHallplanConverter();
        $schema = $converter->convert($source);
        self::assertCount($count, array_merge(...array_column($schema['sectors'], 'seats')));
        // Repeated requests in the same PHP process must not share IDs/state.
        self::assertSame($schema, $converter->convert(['result' => ['hallplan' => $source]]));
        $reflection = new \ReflectionClass(HallService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        // ValidationError, если схема невалидна, просто упадёт тестом —
        // перехватывать её через Reflection бессмысленно (PHPStan считает
        // такой catch мёртвым, потому что не видит исключение сквозь invoke).
        $reflection->getMethod('validateSchemaPayload')->invoke($service, $schema);
        self::assertGreaterThan(0, $schema['canvas']['width']);
        self::assertGreaterThan(0, $schema['canvas']['height']);
    }

    public function test_real_fixtures_report_exact_versus_reconstructed_data(): void
    {
        foreach (self::halls() as [$name, $count]) {
            $source = json_decode(file_get_contents(__DIR__ . '/../Fixtures/hallplans/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
            $schema = (new YandexHallplanConverter())->convert($source);
            $report = $schema['import'];

            self::assertSame($count, $report['known_seats'] + $report['reconstructed_seats'], $name);
            self::assertGreaterThan(0, $report['known_seats'], $name);
            self::assertGreaterThan(0, $report['rows'], 'Отчёт должен считать строки по местам, а не по несуществующему полю rows');
            self::assertSame('reconstructed', $report['exactness'], $name);
            self::assertNotEmpty($report['warnings'], $name);

            $allSeats = array_merge(...array_column($schema['sectors'], 'seats'));
            $withSource = array_filter($allSeats, static fn (array $seat): bool => isset($seat['source_id'], $seat['source_number'], $seat['source_row']));
            self::assertCount($count, $withSource, $name . ' — каждое место обязано нести происхождение');
            self::assertSame('RUB', $schema['currency'], $name);
        }
    }

    public function test_filarmoniya_letter_rows_keep_their_source_designation(): void
    {
        $source = json_decode(file_get_contents(__DIR__ . '/../Fixtures/hallplans/filarmoniya.json'), true, flags: JSON_THROW_ON_ERROR);
        $schema = (new YandexHallplanConverter())->convert($source);
        $rows = array_values(array_unique(array_filter(array_map(
            static fn (array $seat): string => (string) ($seat['source_row'] ?? ''),
            array_merge(...array_column($schema['sectors'], 'seats')),
        ))));

        self::assertContains('А', $rows);
        self::assertContains('Б', $rows);
        self::assertContains('В', $rows);
    }

    public function test_exact_seat_coordinates_are_kept_as_source_coordinates(): void
    {
        $schema = (new YandexHallplanConverter())->convert(['levels' => [[
            'id' => 9, 'name' => 'Партер', 'rows' => [['row' => '1', 'seatCount' => 20, 'xCoord' => 100, 'yCoord' => 200]],
            'seats' => [
                ['seat' => ['row' => '1', 'place' => '7', 'xCoord' => 100, 'yCoord' => 200],
                    'priceInfo' => ['price' => ['value' => 123400]]],
                ['seat' => ['row' => '1', 'place' => '8', 'xCoord' => 120, 'yCoord' => 200],
                    'priceInfo' => ['price' => ['value' => 123400]]],
            ],
        ]]]);
        $seat = null;
        foreach ($schema['sectors'][0]['seats'] as $candidate) {
            if (($candidate['source_number'] ?? null) === '7') {
                $seat = $candidate;
                break;
            }
        }
        self::assertNotNull($seat, 'Известное место 7 должно присутствовать в схеме');

        self::assertSame('yandex_exact', $seat['source']);
        self::assertSame('exact', $seat['confidence']);
        self::assertSame(100.0, $seat['source_x']);
        self::assertSame(200.0, $seat['source_y']);
        self::assertSame('7', $seat['source_number']);
        self::assertSame(123400, $schema['sectors'][0]['rowPrices']['1'] ?? null);
        self::assertSame('yandex_exact', $seat['price_source']);
    }

    public function test_standing_zone_keeps_zero_availability_and_unknown_price(): void
    {
        $schema = (new YandexHallplanConverter())->convert(['levels' => [[
            'id' => 3, 'name' => 'Фан-зона', 'admission' => true, 'rows' => [], 'seats' => [],
            'categories' => [
                ['id' => 11, 'name' => 'Вход', 'availableSeatCount' => 0, 'priceInfos' => []],
            ],
        ]]]);

        self::assertCount(1, $schema['staticObjects'], 'Зона без остатка и без цены не должна исчезать');
        $zone = $schema['staticObjects'][0];
        self::assertSame(0, $zone['capacity']);
        self::assertSame(0, $zone['priceMinor']);
        self::assertFalse($zone['capacity_confirmed']);
        self::assertSame('unknown', $zone['price_source']);
        self::assertSame(1, $schema['import']['unknown_capacity']);
    }

    public function test_multiple_tariffs_do_not_duplicate_zone_capacity(): void
    {
        $schema = (new YandexHallplanConverter())->convert(['levels' => [[
            'id' => 4, 'name' => 'Танцпол', 'admission' => true, 'rows' => [], 'seats' => [],
            'categories' => [
                ['id' => 1, 'name' => 'Танцпол', 'availableSeatCount' => 50,
                    'priceInfos' => [['price' => ['value' => 70000]]]],
                ['id' => 2, 'name' => 'Танцпол + ранний вход', 'availableSeatCount' => 30,
                    'priceInfos' => [['price' => ['value' => 90000]]]],
            ],
        ]]]);

        self::assertCount(1, $schema['staticObjects'], 'Несколько тарифов — одна физическая зона');
        $zone = $schema['staticObjects'][0];
        self::assertSame(50, $zone['capacity'], 'Вместимость не должна складываться из тарифов');
        self::assertCount(2, $zone['tariffs']);
        self::assertFalse($zone['capacity_confirmed']);
    }

    public function test_source_ids_are_stable_between_repeated_imports(): void
    {
        $source = json_decode(file_get_contents(__DIR__ . '/../Fixtures/hallplans/surgu.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new YandexHallplanConverter();
        $first = $converter->convert($source);
        $second = $converter->convert($source);

        $ids = static fn (array $schema): array => array_map(
            static fn (array $seat): string => (string) $seat['source_id'],
            array_merge(...array_column($schema['sectors'], 'seats')),
        );
        self::assertSame($ids($first), $ids($second));
    }

    /** @return array<string, mixed> */
    private function unconfirmedZoneSchema(): array
    {
        return (new YandexHallplanConverter())->convert(['levels' => [[
            'id' => 3, 'name' => 'Фан-зона', 'admission' => true, 'rows' => [], 'seats' => [],
            'categories' => [['id' => 11, 'name' => 'Вход', 'availableSeatCount' => 0, 'priceInfos' => []]],
        ]]]);
    }

    public function test_unconfirmed_standing_zone_survives_draft_validation(): void
    {
        $reflection = new \ReflectionClass(HallService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        // Черновик сохраняет неподтверждённую зону, чтобы её можно было заполнить вручную.
        $reflection->getMethod('validateSchemaPayload')->invoke($service, $this->unconfirmedZoneSchema());
        self::assertTrue(true, 'Валидация черновика не должна отклонять неподтверждённую зону');
    }

    public function test_publication_rejects_unconfirmed_standing_zone(): void
    {
        $schema = $this->unconfirmedZoneSchema();
        $reflection = new \ReflectionClass(HallService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->expectException(\Nabilet\Core\Errors\ValidationError::class);
        $reflection->getMethod('validateSchemaPayload')->invoke($service, $schema, true);
    }

    public function test_confirmed_zone_passes_strict_validation(): void
    {
        $schema = $this->unconfirmedZoneSchema();
        $zone = &$schema['staticObjects'][0];
        $zone['capacity'] = 100;
        $zone['capacity_confirmed'] = true;
        $zone['priceMinor'] = 50000;
        $zone['price_source'] = 'organizer_confirmed';
        unset($zone);

        $reflection = new \ReflectionClass(HallService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('validateSchemaPayload')->invoke($service, $schema, true);
        self::assertTrue(true);
    }

    public function test_rejects_excessive_json_depth(): void
    {
        $deep = ['levels' => []];
        $cursor = &$deep;
        for ($i = 0; $i < 30; $i++) {
            $cursor['next'] = [];
            $cursor = &$cursor['next'];
        }
        unset($cursor);

        $this->expectException(\InvalidArgumentException::class);
        (new YandexHallplanConverter())->convert($deep);
    }

    public function test_rejects_unbounded_seat_generation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new YandexHallplanConverter())->convert(['levels' => [
            ['rows' => [['row' => '1', 'seatCount' => 100000000]], 'seats' => []],
        ]]);
    }

    public function test_standing_only_hall_is_supported(): void
    {
        $schema = (new YandexHallplanConverter())->convert(['levels' => [[
            'id' => 1, 'name' => 'Танцпол', 'admission' => true, 'rows' => [], 'seats' => [],
            'categories' => [['name' => 'Вход', 'availableSeatCount' => 50,
                'priceInfos' => [['price' => ['value' => 100000]]]]],
        ]]]);
        self::assertSame([], $schema['sectors']);
        self::assertSame(50, $schema['staticObjects'][0]['capacity']);
        self::assertSame(100000, $schema['staticObjects'][0]['priceMinor']);
    }
}
