<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Конверсия суммы платежа (десятичная строка провайдера ⇄ minor units) —
 * СОБСТВЕННОСТЬ Nabilet\Core\Support\Money, а не ручных копий в провайдерах.
 *
 * ЧТО БЫЛО. YooKassaProvider собирал тело запроса через
 * `number_format($amount / 100, 2, '.', '')` и разбирал ответ через
 * `(int) round((float) $value * 100)` — ровно ту вещественную арифметику,
 * которую Money существует чтобы запретить («Never float, never a bare decimal
 * string»). Две копии одного правила молча расходятся: при смене формата в
 * Money провайдер продолжил бы отдавать строку по-своему, и 0.1*100 давал бы
 * дрейф на границе точности.
 *
 * Сторож не проверяет «класс существует» — он проверяет, что ВЕСЬ модуль
 * Payments больше не содержит ни number_format(), ни (int) round((float), то
 * есть единственный путь конверсии — через Money::fromDecimal()/toDecimal().
 * Вернуть копию, не сломав тест, нельзя: паттерн отлова вхождения не допускает.
 */
final class MoneyConversionSingleOwnerTest extends TestCase
{
    private const PAYMENTS_DIR = 'app/Modules/Payments';

    private function sourceOfModule(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/' . self::PAYMENTS_DIR;

        $src = '';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $src .= "\n" . (string) file_get_contents($file->getPathname());
            }
        }

        return $src;
    }

    public function test_module_has_no_hand_rolled_amount_formatting(): void
    {
        $src = $this->sourceOfModule();

        // Никакой ручной сборки десятичной строки из minor units.
        $this->assertFalse(
            (bool) preg_match('/number_format\(\s*[\$\w]/', $src),
            'В модуле Payments найден ручной number_format() — конверсия должна идти через Money::toDecimal().'
        );

        // Никакой вещественной арифметики «строка провайдера → minor units».
        $this->assertFalse(
            (bool) preg_match('/\(\s*int\s*\)\s*round\(\s*\(float\)/', $src),
            'В модуле Payments найден (int) round((float) — конверсия должна идти через Money::fromDecimal().'
        );

        // И единственный владелец действительно используется.
        $this->assertStringContainsString('Money::fromDecimal', $src);
        $this->assertStringContainsString('Money::toDecimal', $src);
    }

    public function test_money_decimal_format_matches_provider_contract(): void
    {
        // То, что провайдер шлёт YooKassa и что парсит из ответа, — ровно формат Money.
        $this->assertSame('50000.00', Money::of(5000000, 'RUB')->toDecimal());
        $this->assertSame(5000000, Money::fromDecimal('50000.00', 'RUB')->minorUnits());

        $this->assertSame('25000.00', Money::of(2500000, 'RUB')->toDecimal());
        $this->assertSame(2500000, Money::fromDecimal('25000.00', 'RUB')->minorUnits());

        // Граничное значение: строковая математика Money не теряет точность,
        // в отличие от (float) '0.29' * 100, который уходит в дрейф.
        $this->assertSame(29, Money::fromDecimal('0.29', 'RUB')->minorUnits());
    }
}
