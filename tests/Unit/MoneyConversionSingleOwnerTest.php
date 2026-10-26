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

    private const VENUES_DIR = 'app/Modules/Venues';

    private function sourceOfModule(): string
    {
        return $this->sourceOf(self::PAYMENTS_DIR);
    }

    /** Исходники всех PHP-файлов модуля одним текстом. */
    private function sourceOf(string $relativeDir): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/' . $relativeDir;

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

    /**
     * Импорт площадки тоже не считает цены сам.
     *
     * ЗАЧЕМ РАСШИРЕНИЕ. Сторож выше ограничен модулем Payments, а второй
     * экземпляр той же ошибки жил в `Venues`: `(int) (((int) $x) / 100)` для
     * копеек из SSR-стейта и `(float)` для рублей из JSON-LD. То есть одно
     * правило было скопировано ещё раз, в другом модуле, и проверка по папке
     * этого не видела.
     *
     * Теперь масштаб приводит `Venues\Domain\ExternalPrice` (внутри — `Money`),
     * а контроллер только передаёт ему найденные значения.
     *
     * ПОЧЕМУ ШАБЛОНЫ ПРИМЕНЯЮТСЯ К КОДУ БЕЗ КОММЕНТАРИЕВ. Докблок
     * `ExternalPrice` ЦИТИРУЕТ прежнюю формулу `(int) (((int) $m[3]) / 100)` —
     * чтобы следующий читатель знал, что именно исправлено. Без вырезания
     * комментариев сторож падал бы на собственном пояснении, то есть требовал
     * бы не объяснять причину правила (ср. ловушку «сторож не должен матчить
     * свой же комментарий»).
     */
    public function test_venue_import_does_not_compute_prices_itself(): void
    {
        $src = $this->codeOnly($this->sourceOf(self::VENUES_DIR));

        $this->assertFalse(
            (bool) preg_match('~number_format\s*\(~', $src),
            'В модуле Venues найден ручной number_format() — форматирование принадлежит Money.'
        );

        $this->assertFalse(
            (bool) preg_match('~\bprice\w*\s*[\'"]?\s*=>\s*\(float\)~i', $src),
            'В модуле Venues цена приводится к float — деньги считаются в minor units целыми.'
        );

        $this->assertFalse(
            (bool) preg_match('~/\s*100\b~', $src),
            'В модуле Venues найден переход «копейки → рубли» делением — это делает ExternalPrice.'
        );

        // И владелец действительно используется обеими ветками импорта.
        $this->assertStringContainsString('ExternalPrice::fromMinorUnits', $src);
        $this->assertStringContainsString('ExternalPrice::fromMajorAmount', $src);
    }

    /** Исходник без комментариев: литерал правила в пояснении не должен ловиться. */
    private function codeOnly(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
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
