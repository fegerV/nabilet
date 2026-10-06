<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Venues\Halls\Domain\SchemaPayloadNormalizer;
use Nabilet\Modules\Venues\Halls\Http\Resources\SchemaVersionResource;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Models\Hall;
use Tests\TestCase;

/**
 * Контракт формы payload схемы зала (§54): карты остаются картами.
 *
 * Повод — сверка файла экспорта редактора с тем, что реально лежит на сервере.
 * Поле `sectors[].rowPrices` — карта «номер ряда → цена в копейках». Редактор
 * присылает `rowPrices: {}`, когда цен по рядам нет (например, банкетный зал
 * с ценой за стол). PHP декодирует это в пустой массив, а `json_encode`
 * отдаёт обратно `[]` — и одно и то же поле приходило то объектом, то
 * массивом, в зависимости от данных. Строгий потребитель (импортёр афиши,
 * валидатор по JSON-схеме, типизированный клиент) на такой нестабильности
 * ломается.
 */
final class HallSchemaPayloadContractTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    private HallService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Payload contract ' . Str::random(10),
            'slug' => 'payload-contract-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Payload venue',
            'slug' => 'payload-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Payload hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->hall = Hall::query()->findOrFail($hallId);
        $this->service = app(HallService::class);
    }

    /**
     * Банкетный зал: у столов цен по рядам нет → `rowPrices` приходит пустым.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $sector = [
            'id' => 'sec-table-8',
            'name' => 'Стол 8',
            'x' => 200,
            'y' => 180,
            'priceMinor' => 900000,
            'type' => 'seated',
            'shape' => 'table',
            'rowPrices' => [],
            'seats' => [],
        ];
        for ($i = 0; $i < 8; $i++) {
            $angle = -M_PI / 2 + ($i * 2 * M_PI) / 8;
            $sector['seats'][] = [
                'id' => "seat-$i",
                'row' => 1,
                'number' => $i + 1,
                'kind' => 'standard',
                'x' => (int) round(80 + 60 * cos($angle)),
                'y' => (int) round(80 + 60 * sin($angle)),
            ];
        }

        return array_merge([
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 620],
            'sectors' => [$sector],
            'staticObjects' => [],
        ], $overrides);
    }

    public function test_empty_row_prices_are_persisted_as_a_json_object_not_an_array(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->payload(), 1);

        $raw = (string) DB::table('hall_schema_versions')->where('id', $draft->id)->value('schema_json');

        // MySQL отдаёт JSON с пробелами после двоеточия, поэтому проверяем
        // регуляркой, а не точной подстрокой.
        self::assertMatchesRegularExpression(
            '/"rowPrices":\s*\{\}/',
            $raw,
            'Пустая карта цен по рядам обязана храниться как JSON-объект, а не как []',
        );
        self::assertDoesNotMatchRegularExpression('/"rowPrices":\s*\[\]/', $raw);
    }

    public function test_api_returns_row_prices_as_an_object(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->payload(), 1);

        $resource = (new SchemaVersionResource($draft->fresh()))->toArray(request());
        $json = json_encode($resource['schema'], JSON_UNESCAPED_UNICODE);

        self::assertStringContainsString('"rowPrices":{}', (string) $json);
    }

    public function test_non_empty_row_prices_keep_their_keys_and_values(): void
    {
        $payload = $this->payload();
        $payload['sectors'][0]['rowPrices'] = [1 => 150000, 2 => 90000];

        $draft = $this->service->createSchemaDraft($this->hall, $payload, 1);

        $raw = (string) DB::table('hall_schema_versions')->where('id', $draft->id)->value('schema_json');
        self::assertMatchesRegularExpression('/"rowPrices":\s*\{\s*"1":\s*150000,\s*"2":\s*90000\s*\}/', $raw);

        $resource = (new SchemaVersionResource($draft->fresh()))->toArray(request());
        $json = (string) json_encode($resource['schema'], JSON_UNESCAPED_UNICODE);
        self::assertMatchesRegularExpression('/"rowPrices":\s*\{\s*"1":\s*150000,\s*"2":\s*90000\s*\}/', $json);
    }

    public function test_normalization_does_not_touch_other_payload_fields(): void
    {
        $payload = $this->payload();
        $payload['staticObjects'] = [[
            'id' => 'stage-1', 'kind' => 'stage', 'x' => 300, 'y' => 20,
            'width' => 300, 'height' => 60, 'text' => 'Сцена',
        ]];

        $normalized = SchemaPayloadNormalizer::normalize($payload);

        self::assertSame($payload['version'], $normalized['version']);
        self::assertSame($payload['canvas'], $normalized['canvas']);
        self::assertSame($payload['staticObjects'], $normalized['staticObjects']);
        self::assertSame('Стол 8', $normalized['sectors'][0]['name']);
        self::assertSame($payload['sectors'][0]['seats'], $normalized['sectors'][0]['seats']);
        self::assertSame(900000, $normalized['sectors'][0]['priceMinor']);
    }

    public function test_normalization_is_idempotent(): void
    {
        $once = SchemaPayloadNormalizer::normalize($this->payload());
        $twice = SchemaPayloadNormalizer::normalize($once);

        self::assertSame(
            json_encode($once, JSON_UNESCAPED_UNICODE),
            json_encode($twice, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_normalizer_tolerates_payloads_without_sectors(): void
    {
        self::assertSame([], SchemaPayloadNormalizer::normalize([]));
        self::assertSame(['canvas' => ['width' => 1, 'height' => 1]], SchemaPayloadNormalizer::normalize([
            'canvas' => ['width' => 1, 'height' => 1],
        ]));
    }
}
