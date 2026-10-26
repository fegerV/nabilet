<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Venues\Domain\ExternalPrice;
use Nabilet\Tests\Support\TestCase;

/**
 * Цена из чужой страницы — одно правило на два формата источника.
 *
 * ЧТО БЫЛО. `VenueImportController` отвечал на вопрос «сколько стоит событие»
 * двумя способами: SSR-стейт Яндекс.Афиши присылает КОПЕЙКИ, и код делал
 * `(int) (((int) $x) / 100)` — вещественное деление с отбрасыванием остатка,
 * поэтому 2999 копеек становились 29 рублями вместо 30. JSON-LD присылает
 * РУБЛИ десятичной строкой, и там стоял `(float)`. Наружу обе ветки уходили в
 * РАЗНЫХ масштабах, а ошибки не возникало: обе отвечали 200.
 *
 * ЧТО ПРОВЕРЯЕТСЯ ЗДЕСЬ
 *
 *   1. Оба формата дают ОДИН масштаб — minor units. Это и есть суть правки:
 *      `"230000"` (копейки) и `"2300.00"` (рубли) обязаны сойтись в одну сумму.
 *   2. Ни один копеечный остаток не теряется: `"230050"` остаётся `230050`.
 *   3. Мусор от чужого сайта — это «цены нет» (`null`), а не исключение:
 *      страница провайдера не должна ронять предпросмотр пятисоткой.
 *   4. Отрицательная цена — отказ. Знак суммы не выражает скидку, и `-100` в
 *      предпросмотре означал бы «источник прислал не то».
 *   5. Валюта нормализуется в одном месте: `"rub"` и `"RUB"` — одна валюта,
 *      отсутствие валюты — `RUB` (основная у источника).
 */
final class ExternalPriceTest extends TestCase
{
    // ── minor units (SSR-стейт) ─────────────────────────────────────────────

    public function test_minor_units_are_taken_as_they_are(): void
    {
        $price = ExternalPrice::fromMinorUnits('230000', 'rub');

        static::assertNotNull($price);
        static::assertSame(230000, $price->minorUnits());
        static::assertSame('RUB', $price->currency(), 'валюта нормализуется в верхний регистр');
    }

    /**
     * Копеечный остаток не теряется — это и был дефект.
     *
     * `(int) (230050 / 100)` дал бы 2300, то есть цену на 50 копеек ниже
     * настоящей. Здесь сумма обязана дойти до потребителя целиком.
     */
    public function test_the_kopeck_remainder_is_not_lost(): void
    {
        $price = ExternalPrice::fromMinorUnits('230050', 'RUB');

        static::assertNotNull($price);
        static::assertSame(230050, $price->minorUnits());
    }

    public function test_minor_units_accept_an_integer_too(): void
    {
        $price = ExternalPrice::fromMinorUnits(230000, 'RUB');

        static::assertNotNull($price);
        static::assertSame(230000, $price->minorUnits());
    }

    public function test_minor_units_reject_anything_that_is_not_a_plain_number(): void
    {
        static::assertNull(ExternalPrice::fromMinorUnits(null));
        static::assertNull(ExternalPrice::fromMinorUnits(''));
        static::assertNull(ExternalPrice::fromMinorUnits('2300.00'));
        static::assertNull(ExternalPrice::fromMinorUnits('-230000'));
        static::assertNull(ExternalPrice::fromMinorUnits('2 300'));
        static::assertNull(ExternalPrice::fromMinorUnits('abc'));
        // 16 цифр — не цена, а случайно пойманная строка; `(int)` на ней уже
        // переполнялся бы.
        static::assertNull(ExternalPrice::fromMinorUnits('1234567890123456'));
    }

    // ── major units (JSON-LD) ───────────────────────────────────────────────

    public function test_major_amount_is_parsed_into_minor_units(): void
    {
        $price = ExternalPrice::fromMajorAmount('2500.00', 'RUB');

        static::assertNotNull($price);
        static::assertSame(250000, $price->minorUnits());
    }

    public function test_major_amount_accepts_a_number_and_keeps_kopecks(): void
    {
        $whole = ExternalPrice::fromMajorAmount(2500, 'RUB');
        static::assertNotNull($whole);
        static::assertSame(250000, $whole->minorUnits());

        $fractional = ExternalPrice::fromMajorAmount(2500.5, 'RUB');
        static::assertNotNull($fractional);
        static::assertSame(250050, $fractional->minorUnits());
    }

    /**
     * Тот же смысл, разная запись — одна сумма.
     *
     * Это главный тест файла: он связывает две ветки импорта между собой.
     * Пока они считались отдельно, «2 300,00 ₽» из JSON-LD и «230000» из стейта
     * уезжали в ответ как 2300 и 230000 — то есть в 100 раз отличались.
     */
    public function test_both_source_formats_agree_on_the_same_price(): void
    {
        $fromJsonLd = ExternalPrice::fromMajorAmount('2300.00', 'RUB');
        $fromApollo = ExternalPrice::fromMinorUnits('230000', 'RUB');

        static::assertNotNull($fromJsonLd);
        static::assertNotNull($fromApollo);
        static::assertSame($fromApollo->minorUnits(), $fromJsonLd->minorUnits());
        static::assertSame($fromApollo->currency(), $fromJsonLd->currency());
    }

    public function test_major_amount_accepts_a_comma_as_the_decimal_separator(): void
    {
        $price = ExternalPrice::fromMajorAmount('2500,50', 'RUB');

        static::assertNotNull($price);
        static::assertSame(250050, $price->minorUnits());
    }

    public function test_major_amount_rejects_garbage_and_negative_values(): void
    {
        static::assertNull(ExternalPrice::fromMajorAmount(null));
        static::assertNull(ExternalPrice::fromMajorAmount(''));
        static::assertNull(ExternalPrice::fromMajorAmount('   '));
        static::assertNull(ExternalPrice::fromMajorAmount('от 2500'));
        static::assertNull(ExternalPrice::fromMajorAmount('1e5'));
        static::assertNull(ExternalPrice::fromMajorAmount(-2500), 'знак суммы не выражает скидку');
        static::assertNull(ExternalPrice::fromMajorAmount('-0.01'));
    }

    // ── валюта ──────────────────────────────────────────────────────────────

    public function test_currency_defaults_to_rub_and_is_normalized(): void
    {
        static::assertSame('RUB', ExternalPrice::fromMinorUnits('100')?->currency());
        static::assertSame('RUB', ExternalPrice::fromMinorUnits('100', '')?->currency());
        static::assertSame('RUB', ExternalPrice::fromMinorUnits('100', '   ')?->currency());
        static::assertSame('KZT', ExternalPrice::fromMajorAmount('100', 'kzt')?->currency());
    }
}
