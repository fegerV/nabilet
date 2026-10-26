<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Цена «от N ₽» на посадочной — через Money::format(), а не свою ветку.
 *
 * Дефект: `LandingPageService::priceFrom()` уже звал `Money::format()` для
 * дробных цен, но для целых рублей держал СВОЮ ветку ручного форматирования —
 * копию того же правила. Она разошлась бы с билетом и письмом при смене
 * разделителя или символа валюты. Флаг `omitWholeKopecks` (Batch 46) позволяет
 * владельцу отдать ровно тот вид, который нужен витрине («2 500 ₽»), поэтому
 * копия больше не нужна.
 */
final class LandingPriceFormatSingleOwnerTest extends TestCase
{
    private function projectFile(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $src = @file_get_contents($path);

        static::assertTrue(is_string($src), "Ожидался файл-владелец правила: {$rel}");

        return is_string($src) ? $src : '';
    }

    public function test_landing_service_delegates_price_to_money_format(): void
    {
        $src = $this->projectFile('app/Modules/Seo/Services/LandingPageService.php');

        // Возврат копии: ручное форматирование денег в сервисе.
        static::assertSame(0, preg_match('/number_format\s*\(/', $src),
            'Цена на посадочной не должна форматироваться вручную — только через Money::format().');
        static::assertStringContainsString("->format('ru_RU', omitWholeKopecks: true)", $src,
            'Сервис обязан звать Money::format() с флагом опущения копеек.');
    }

    public function test_money_format_matches_storefront_whole_ruble_rule(): void
    {
        // Целые рубли — без копеек, как на витрине («от 2 500 ₽»).
        static::assertSame('2 500 ₽', Money::of(250000, 'RUB')->format('ru_RU', omitWholeKopecks: true));
        // Дробные — с копейками, когда они есть.
        static::assertSame('2 500,50 ₽', Money::of(250050, 'RUB')->format('ru_RU', omitWholeKopecks: true));
    }
}
