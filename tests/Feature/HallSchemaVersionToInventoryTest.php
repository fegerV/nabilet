<?php

declare(strict_types=1);

namespace Tests\Feature;

use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Конвертер канвасного формата редактора (sectors[].seats[] с x/y)
 * в rows-формат генератора инвентаря (sectors[].rows[].seats[] c price_amount).
 * Регрессия: импортированная схема Афиши (rows-формат) не должна ломаться.
 */
class HallSchemaVersionToInventoryTest extends TestCase
{
    private function makeVersion(array $schema): HallSchemaVersion
    {
        $v = new HallSchemaVersion();
        $v->schema_json = $schema;
        return $v;
    }

    public function test_canvas_format_converts_to_rows(): void
    {
        $v = $this->makeVersion([
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Партер', 'code' => 'P', 'type' => 'seated',
                'priceMinor' => 500000,
                'seats' => [
                    ['id' => 'a', 'row' => 1, 'number' => 1, 'kind' => 'standard', 'x' => 100, 'y' => 50],
                    ['id' => 'b', 'row' => 1, 'number' => 2, 'kind' => 'standard', 'x' => 140, 'y' => 50],
                    ['id' => 'c', 'row' => 2, 'number' => 1, 'kind' => 'vip', 'x' => 100, 'y' => 90],
                ],
            ]],
        ]);

        $out = $v->toInventoryFormat();

        // Сектор один; группа мест по рядам
        $this->assertSame(1, count($out));
        $this->assertSame('Партер', $out[0]['name']);
        $this->assertSame(2, count($out[0]['rows']));

        // Ряд 1: 2 места, цена из priceMinor, координаты нормализованы в 60×40
        $r1 = $out[0]['rows'][0];
        $this->assertSame('1', $r1['number']);
        $this->assertSame(500000, $r1['price_amount']);
        $this->assertSame(2, count($r1['seats']));
        $this->assertSame(7, $r1['seats'][0]['x']);  // 100/900*60 ≈ 7
        $this->assertSame(4, $r1['seats'][0]['y']);  // 50/520*40 ≈ 4
        $this->assertSame(1, $r1['seats'][0]['id']); // числовой id (BIGINT seat_id)

        // Ряд 2: 1 место, vip
        $r2 = $out[0]['rows'][1];
        $this->assertSame('2', $r2['number']);
        $this->assertSame(1, count($r2['seats']));
        $this->assertSame('vip', $r2['seats'][0]['type']);
    }

    public function test_row_prices_override_sector_price(): void
    {
        $v = $this->makeVersion([
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Партер', 'code' => 'P', 'type' => 'seated',
                'priceMinor' => 100000,
                'rowPrices' => ['2' => 250000],
                'seats' => [
                    ['id' => 'a', 'row' => 1, 'number' => 1, 'kind' => 'standard', 'x' => 100, 'y' => 50],
                    ['id' => 'b', 'row' => 2, 'number' => 1, 'kind' => 'standard', 'x' => 100, 'y' => 90],
                ],
            ]],
        ]);

        $out = $v->toInventoryFormat();

        $this->assertSame(100000, $out[0]['rows'][0]['price_amount']);
        $this->assertSame(250000, $out[0]['rows'][1]['price_amount']);
    }

    public function test_rows_format_passes_through_unchanged(): void
    {
        // Формат импортёра Афиши — rows уже присутствуют, координаты не нормализуются
        $v = $this->makeVersion([
            'sectors' => [[
                'name' => 'Танцпол', 'type' => 'standing',
                'rows' => [[
                    'number' => '1', 'label' => 'Танцпол', 'price_amount' => 299900,
                    'seats' => [[
                        'id' => 'z', 'number' => '1', 'label' => 'Танцпол 1', 'type' => 'regular',
                        'x' => 30, 'y' => 35,
                    ]],
                ]],
            ]],
        ]);

        $out = $v->toInventoryFormat();

        $this->assertSame(1, count($out));
        $this->assertSame('Танцпол', $out[0]['name']);
        $this->assertSame(1, count($out[0]['rows']));
        $this->assertSame(299900, $out[0]['rows'][0]['price_amount']);
        $this->assertSame(30, $out[0]['rows'][0]['seats'][0]['x']);
    }
}