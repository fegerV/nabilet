<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Venues\Domain\SchemaPrice;
use Nabilet\Tests\Support\TestCase;

/**
 * Действующая цена узла схемы зала — одно правило вместо семи копий.
 *
 * ЧТО БЫЛО. «Сколько стоит это место» решалось в схеме зала семью цепочками
 * запасных вариантов: три в `HallSchemaVersion::toInventoryFormat()`, две в
 * `HallService::validateSchemaPayload()`, одна в `InventoryService` и ещё две в
 * клиентском `schemaSerialization`. Цепочки были записаны по-разному и уже
 * разошлись: чтец падал на легаси `price` через `(int)`, а валидатор места
 * смотрел только на цену ряда и сектора — индивидуальная цена места в нём не
 * участвовала вовсе.
 *
 * ЧТО ПРОВЕРЯЕТСЯ ЗДЕСЬ
 *
 *   1. Приоритет ровно тот, что обещан в подсказке админки: место → ряд →
 *      сектор.
 *   2. `priceMinor` побеждает легаси `price`, но `price` читается — иначе
 *      старые схемы превратились бы в нулевые.
 *   3. Объявленный НОЛЬ — это ответ, а не «не задано»: цепочка не проваливается
 *      на следующий уровень. Иначе нулевая цена сектора молча подменялась бы
 *      легаси-полем.
 *   4. Индивидуальная цена места при некорректном значении означает «своей
 *      цены нет» (наследуем ряд), а не «цена ноль»: иначе место продавалось бы
 *      бесплатно.
 *   5. Вещественное число отвергается: деньги в проекте целые (ТЗ §3).
 */
final class SchemaPriceTest extends TestCase
{
    // ── правило 1: два имени одного поля ────────────────────────────────────

    public function test_price_minor_wins_over_the_legacy_price_field(): void
    {
        $price = SchemaPrice::ofNode(['priceMinor' => 500000, 'price' => 100000]);

        static::assertNotNull($price);
        static::assertSame(500000, $price->minorUnits());
    }

    public function test_the_legacy_price_field_is_still_read(): void
    {
        // Старые сидеры и импорт Афиши пишут то же число в `price`.
        $price = SchemaPrice::ofNode(['price' => 250000]);

        static::assertNotNull($price);
        static::assertSame(250000, $price->minorUnits(), 'легаси-поле — та же цена, а не рубли');
    }

    public function test_a_declared_zero_is_an_answer_and_does_not_fall_through(): void
    {
        $price = SchemaPrice::ofNode(['priceMinor' => 0, 'price' => 250000]);

        static::assertNotNull($price);
        static::assertSame(0, $price->minorUnits(), 'ноль не должен подменяться легаси-полем');
        static::assertFalse($price->isSellable());
    }

    public function test_a_node_without_any_price_field_has_no_price(): void
    {
        static::assertNull(SchemaPrice::ofNode([]));
        static::assertNull(SchemaPrice::ofNode(['name' => 'Партер']));
    }

    public function test_a_non_node_is_not_a_price_source(): void
    {
        static::assertNull(SchemaPrice::ofNode(null));
        static::assertNull(SchemaPrice::ofNode('5000'));
        static::assertNull(SchemaPrice::ofNode(5000));
    }

    // ── правило 2: приоритет место → ряд → сектор ───────────────────────────

    public function test_the_row_price_overrides_the_sector_price(): void
    {
        $sector = ['priceMinor' => 100000, 'rowPrices' => ['2' => 250000]];

        static::assertSame(250000, SchemaPrice::forRow(2, $sector)?->minorUnits());
        static::assertSame(250000, SchemaPrice::forRow('2', $sector)?->minorUnits());
        static::assertSame(100000, SchemaPrice::forRow(1, $sector)?->minorUnits(), 'ряд без своей цены берёт цену сектора');
    }

    public function test_a_row_price_key_is_found_as_a_string_and_as_an_integer(): void
    {
        // `rowPrices` приезжает из JSON-объекта (ключи-строки), но редактор
        // строит его как Record<number, number> — после round-trip ключи целые.
        static::assertSame(300000, SchemaPrice::forRow(3, ['rowPrices' => [3 => 300000]])?->minorUnits());
        static::assertSame(300000, SchemaPrice::forRow(3, ['rowPrices' => ['3' => 300000]])?->minorUnits());
    }

    public function test_the_row_level_falls_back_to_the_legacy_sector_price(): void
    {
        $price = SchemaPrice::forRow(1, ['price' => 150000]);

        static::assertNotNull($price);
        static::assertSame(150000, $price->minorUnits());
    }

    public function test_a_zero_row_price_does_not_fall_through_to_the_sector(): void
    {
        $price = SchemaPrice::forRow(1, ['priceMinor' => 100000, 'rowPrices' => ['1' => 0]]);

        static::assertNotNull($price);
        static::assertSame(0, $price->minorUnits(), 'ряд с ценой 0 не должен продаваться по цене сектора');
        static::assertFalse($price->isSellable());
    }

    // ── стоячая зона ────────────────────────────────────────────────────────

    public function test_a_standing_zone_reads_its_own_price(): void
    {
        $price = SchemaPrice::forStandingZone(['kind' => 'standing', 'priceMinor' => 120000]);

        static::assertNotNull($price);
        static::assertSame(120000, $price->minorUnits());
    }

    public function test_a_lifted_standing_zone_falls_back_to_its_sector(): void
    {
        // Зона без своей цены «поднимается» в сектор-обёртку: цена берётся у сектора.
        $price = SchemaPrice::forStandingZone(
            ['kind' => 'standing', 'capacity' => 800],
            ['name' => 'Манеж', 'price' => 90000],
        );

        static::assertNotNull($price);
        static::assertSame(90000, $price->minorUnits());
    }

    public function test_a_zone_without_a_sector_and_without_a_price_has_no_price(): void
    {
        static::assertNull(SchemaPrice::forStandingZone(['kind' => 'standing', 'capacity' => 800]));
    }

    // ── индивидуальная цена места ───────────────────────────────────────────

    public function test_a_seat_override_is_taken_when_it_is_a_real_price(): void
    {
        static::assertSame(80000, SchemaPrice::seatOverride(80000)?->minorUnits());
        static::assertSame(80000, SchemaPrice::seatOverride('80000')?->minorUnits());
    }

    public function test_an_invalid_seat_override_means_no_own_price(): void
    {
        // null, а не «цена ноль»: место наследует цену ряда.
        static::assertNull(SchemaPrice::seatOverride(0));
        static::assertNull(SchemaPrice::seatOverride(-5000));
        static::assertNull(SchemaPrice::seatOverride(null));
        static::assertNull(SchemaPrice::seatOverride(''));
        static::assertNull(SchemaPrice::seatOverride('дорого'));
        static::assertNull(SchemaPrice::seatOverride(2999.5), 'деньги целые: вещественное число — не цена');
        static::assertNull(SchemaPrice::seatOverride(true), 'bool — не цена');
    }

    // ── разбор значения ─────────────────────────────────────────────────────

    public function test_a_digit_string_is_the_same_price(): void
    {
        // Схема проходит через JSON и обратно: "5000" и 5000 — одна цена.
        static::assertSame(5000, SchemaPrice::ofNode(['priceMinor' => '5000'])?->minorUnits());
        static::assertSame(5000, SchemaPrice::ofNode(['priceMinor' => ' 5000 '])?->minorUnits());
    }

    public function test_garbage_is_not_a_price(): void
    {
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => '2300.00']), 'десятичная запись — не копейки');
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => '2 300']));
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => 'abc']));
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => 2999.5]));
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => [5000]]));
        // 16 цифр — не цена, а случайно пойманное значение; `(int)` переполнился бы.
        static::assertNull(SchemaPrice::ofNode(['priceMinor' => '1234567890123456']));
    }

    public function test_a_garbage_value_falls_through_to_the_next_name(): void
    {
        // `"abc"` в `priceMinor` — не цена, поэтому читается легаси-поле.
        // Это отличается от объявленного нуля (см. выше): ноль — ответ, мусор — нет.
        $price = SchemaPrice::ofNode(['priceMinor' => 'abc', 'price' => 250000]);

        static::assertNotNull($price);
        static::assertSame(250000, $price->minorUnits());
    }

    // ── продажность ─────────────────────────────────────────────────────────

    public function test_only_a_positive_whole_kopeck_amount_is_sellable(): void
    {
        static::assertTrue(SchemaPrice::ofNode(['priceMinor' => 1])?->isSellable() === true);
        static::assertFalse(SchemaPrice::ofNode(['priceMinor' => 0])?->isSellable() === true);
        static::assertFalse(SchemaPrice::ofNode(['priceMinor' => -1])?->isSellable() === true);
    }

    // ── словарь ─────────────────────────────────────────────────────────────

    public function test_the_two_names_of_the_price_field_are_declared_once(): void
    {
        static::assertSame(['priceMinor', 'price'], SchemaPrice::NODE_KEYS);
    }
}
