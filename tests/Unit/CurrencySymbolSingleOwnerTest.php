<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Соответствие валюта→символ (RUB→₽, USD→$, EUR→€) — СОБСТВЕННОСТЬ
 * Nabilet\Core\Support\Money::symbolFor(), а не ручной копии в рендерере билета.
 *
 * ЧТО БЫЛО. TicketPdfRenderer собирал `ticket.currency` через
 * `$currency === 'RUB' ? '₽' : $currency` — ровно ветку карты символов, которую
 * Money::format() уже содержит. Копия молча расходилась бы при добавлении валюты
 * (например, USD показывался бы кодом «USD», а в письме — символом «$»).
 *
 * Сторож проверяет, что файл рендерера больше не содержит ручной ветки выбора
 * символа и зовёт Money::symbolFor() (вернуть копию, не сломав тест, нельзя),
 * плюс поведенчески фиксирует саму карту символов.
 */
final class CurrencySymbolSingleOwnerTest extends TestCase
{
    private const TARGET_FILE = 'app/Modules/Tickets/Services/TicketPdfRenderer.php';

    private function targetSource(): string
    {
        $root = dirname(__DIR__, 2);

        return (string) file_get_contents($root . '/' . self::TARGET_FILE);
    }

    public function test_target_delegates_to_money_symbol_and_has_no_hand_rolled_branch(): void
    {
        $src = $this->targetSource();

        // Никакой ручной ветки выбора символа по валюте в этом файле.
        $this->assertFalse(
            (bool) preg_match("/\\\$currency\\s*===\\s*'RUB'\\s*\\?\\s*'₽'/", $src),
            'В TicketPdfRenderer найдена ручная ветка валюты→символ — должна идти через Money::symbolFor().'
        );

        // Единственный владелец карты символов действительно вызывается.
        $this->assertStringContainsString('Money::symbolFor', $src);
    }

    public function test_symbol_map_is_locked(): void
    {
        // Источник истины для символа валюты.
        $this->assertSame('₽', Money::symbolFor('RUB'));
        $this->assertSame('$', Money::symbolFor('USD'));
        $this->assertSame('€', Money::symbolFor('EUR'));

        // format() пользуется тем же владельцем — вывод для RUB не изменился.
        $this->assertSame('50 000,00 ₽', Money::of(5000000, 'RUB')->format('ru_RU'));
        $this->assertSame('25 000,00 ₽', Money::of(2500000, 'RUB')->format('ru_RU'));
    }
}
