<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Билетная цена форматируется в единственном месте — Money::format().
 *
 * Дефект, который этот сторож делает невозможным вернуть: источник
 * (TicketGeneratorService) отдавал вещественное `$minorAmount / 100`, а два
 * потребителя — письмо (ticket.blade.php) и PDF (TicketPdfRenderer) — заново
 * собирали строку через number_format() каждый со своим правилом точности.
 * Вторая копия одного правила молча расходится: письмо показывало код «RUB»,
 * PDF — символ «₽», а при смене разделителя каналы разъехались бы. Теперь
 * правило живёт в Money::format(), а каналы лишь зовут его с разным флагом
 * точности.
 */
final class TicketPriceFormatSingleOwnerTest extends TestCase
{
    private function projectFile(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        $src = @file_get_contents($path);

        static::assertTrue(is_string($src), "Ожидался файл-владелец правила: {$rel}");

        return is_string($src) ? $src : '';
    }

    public function test_blade_delegates_price_to_money_format_and_has_no_inline_number_format(): void
    {
        $src = $this->projectFile('app/Modules/Tickets/Resources/views/email/ticket.blade.php');

        // Возврат копии: ручное форматирование цены в шаблоне.
        static::assertSame(0, preg_match('/number_format\s*\(/', $src),
            'Цена в письме не должна форматироваться вручную — только через Money::format().');
        static::assertStringContainsString("->format('ru_RU')", $src,
            'Письмо обязано звать Money::format() владельца.');
    }

    public function test_pdf_renderer_delegates_price_to_money_format_and_has_no_inline_number_format(): void
    {
        $src = $this->projectFile('app/Modules/Tickets/Services/TicketPdfRenderer.php');

        static::assertSame(0, preg_match('/number_format\s*\(/', $src),
            'Цена в PDF не должна форматироваться вручную — только через Money::format().');
        static::assertStringContainsString("->format('ru_RU', omitWholeKopecks: true)", $src,
            'PDF обязан звать Money::format() с флагом опущения копеек.');
    }

    public function test_generator_emits_money_not_a_float_division(): void
    {
        $src = $this->projectFile('app/Modules/Tickets/Services/TicketGeneratorService.php');

        // Возврат копии: конверсия minor→десятичное через вещественное деление.
        static::assertSame(0, preg_match('/\$minorAmount\s*\/\s*100/', $src),
            'Источник не должен делить minor-единицы как float — только Money::of().');
        static::assertStringContainsString('Money::of($minorAmount', $src,
            'Источник обязан строить Money, а не float.');
    }

    public function test_money_format_is_single_owner_of_price_display(): void
    {
        // PDF: целые рубли — без копеек («3 500 ₽»).
        static::assertSame('3 500 ₽', Money::of(350000, 'RUB')->format('ru_RU', omitWholeKopecks: true));
        // PDF: дробные — с копейками («3 500,50 ₽»).
        static::assertSame('3 500,50 ₽', Money::of(350050, 'RUB')->format('ru_RU', omitWholeKopecks: true));

        // Письмо (без флага): всегда две копейки («3 500,00 ₽»).
        static::assertSame('3 500,00 ₽', Money::of(350000, 'RUB')->format('ru_RU'));
        static::assertSame('0,00 ₽', Money::of(0, 'RUB')->format('ru_RU', omitWholeKopecks: true));

        // Валюта приходит от владельца символа — USD и EUR тоже едины для обоих каналов.
        static::assertSame('1 000,00 $', Money::of(100000, 'USD')->format('ru_RU'));
        static::assertSame('1 000 €', Money::of(100000, 'EUR')->format('ru_RU', omitWholeKopecks: true));
    }
}
