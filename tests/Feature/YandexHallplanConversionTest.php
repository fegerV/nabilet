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
