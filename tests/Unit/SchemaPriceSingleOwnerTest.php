<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Действующая цена узла схемы зала вычисляется ТОЛЬКО в `SchemaPrice`.
 *
 * ЧТО БЫЛО. Семь копий одной цепочки запасных вариантов: три в
 * `HallSchemaVersion::toInventoryFormat()`, две в
 * `HallService::validateSchemaPayload()`, одна в `InventoryService` и ещё две в
 * клиентском `schemaSerialization`. Пока копии совпадали, расхождение не
 * проявлялось; оно проявилось бы при первом изменении приоритета — молча,
 * потому что обе ветки отвечают 200.
 *
 * ЧТО ИМЕННО ЛОВИТСЯ. Не «упоминание двух имён поля», а КОАЛЕСЦЕНЦИЯ по ним:
 * `['priceMinor'] ?? … ['price']` — то есть место, где кто-то сам решил, какое
 * из двух имён главнее. Проверка каждого объявленного поля по отдельности
 * (`array_key_exists('priceMinor', …)` и рядом `array_key_exists('price', …)`)
 * законна: это валидация двух разных полей, а не выбор цены.
 *
 * ПОЧЕМУ НА КЛИЕНТЕ ТОЖЕ. Копия в JS опаснее серверной: она не проходит ни
 * гейты, ни PHP-тесты и расходится молча — редактор показал бы ноль там, где
 * сервер продаёт по легаси-полю.
 *
 * Сторож проверяет сам себя: паттерн обязан совпасть с заведомо плохим
 * образцом. Иначе сломанная регулярка «проходила» бы, ничего не проверив.
 */
final class SchemaPriceSingleOwnerTest extends TestCase
{
    private const OWNER = 'app/Modules/Venues/Domain/SchemaPrice.php';

    private const APP_DIR = 'app';

    private const JS_DIR = 'resources/js';

    /** `$x['priceMinor'] ?? … $x['price']` — выбор между двумя именами цены. */
    private const PHP_CHAIN = '~\[[\'"]priceMinor[\'"]\][^\n]*\?\?[^\n]*\[[\'"]price[\'"]\]~';

    /** То же в обратном порядке: легаси-поле как основное. */
    private const PHP_CHAIN_REVERSED = '~\[[\'"]price[\'"]\][^\n]*\?\?[^\n]*\[[\'"]priceMinor[\'"]\]~';

    /** `priceMinor ?? … .price` в JS/TS. */
    private const TS_CHAIN = '~priceMinor\s*\?\?[^\n]*\.price\b~';

    /** `rowPrices[ряд] ?? …` — уровень ряда, выбранный на месте. */
    private const TS_ROW_CHAIN = '~rowPrices\[[^\]\n]+\]\s*\?\?~';

    /**
     * Паттерн обязан ловить прежнюю формулу — иначе он бесполезен.
     *
     * Образцы взяты дословно из удалённого кода (см. докблок класса), а не
     * придуманы: сторож должен падать ровно на том, что было.
     */
    public function test_the_patterns_catch_the_chains_that_were_removed(): void
    {
        $phpBad = [
            "(int) (\$obj['priceMinor'] ?? \$obj['price'] ?? \$sector['priceMinor'] ?? \$sector['price'] ?? 0)",
            "\$price = \$object['priceMinor'] ?? \$object['price'] ?? null;",
            "\$effectivePrice = \$rowPrices[(string) \$rowNo] ?? \$sector['priceMinor'] ?? \$sector['price'] ?? null;",
        ];

        foreach ($phpBad as $sample) {
            static::assertTrue(
                (bool) preg_match(self::PHP_CHAIN, $sample) || (bool) preg_match(self::PHP_CHAIN_REVERSED, $sample),
                'Паттерн не поймал прежнюю формулу: ' . $sample
            );
        }

        $jsBad = [
            "priceMinor: Math.max(0, Math.round(num(s.priceMinor ?? s.price, 0))),",
            "const price = seat.priceMinor ?? sector.rowPrices[seat.row] ?? sector.priceMinor",
            "let secPrice = num(s.priceMinor ?? s.price, NaN)",
        ];

        foreach ($jsBad as $sample) {
            static::assertTrue(
                (bool) preg_match(self::TS_CHAIN, $sample) || (bool) preg_match(self::TS_ROW_CHAIN, $sample),
                'Паттерн не поймал прежнюю формулу: ' . $sample
            );
        }
    }

    public function test_no_php_file_resolves_the_node_price_itself(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::APP_DIR) as $path) {
            // Комментарии вырезаются: докблок владельца ЦИТИРУЕТ прежнюю
            // цепочку, чтобы следующий читатель знал, что исправлено. Без
            // вырезания сторож падал бы на собственном пояснении.
            $code = $this->codeOnly((string) file_get_contents($path));

            if (preg_match(self::PHP_CHAIN, $code) === 1
                || preg_match(self::PHP_CHAIN_REVERSED, $code) === 1) {
                $offenders[] = $path;
            }
        }

        static::assertSame(
            [],
            $offenders,
            'Цена узла схемы выбирается мимо SchemaPrice: ' . implode(', ', $offenders)
        );
    }

    public function test_no_client_file_resolves_the_price_itself(): void
    {
        $offenders = [];

        foreach ($this->scriptFiles(self::JS_DIR) as $path) {
            // Владелец на клиенте — lib/hall.ts: там эти цепочки и живут.
            if (str_ends_with($path, 'lib/hall.ts')) {
                continue;
            }

            $code = $this->codeOnly((string) file_get_contents($path));

            if (preg_match(self::TS_CHAIN, $code) === 1 || preg_match(self::TS_ROW_CHAIN, $code) === 1) {
                $offenders[] = $path;
            }
        }

        static::assertSame(
            [],
            $offenders,
            'Цена выбирается мимо lib/hall.ts: ' . implode(', ', $offenders)
        );
    }

    /**
     * Владелец обязан остаться единственным путём: он объявляет оба имени и
     * действительно используется каждой границей.
     */
    public function test_every_boundary_calls_the_owner(): void
    {
        // Владелец объявляет оба имени поля ровно один раз — здесь.
        $owner = $this->read(self::OWNER);
        static::assertStringContainsString('public const NODE_KEYS', $owner);
        static::assertStringContainsString("'priceMinor'", $owner);
        static::assertStringContainsString("'price'", $owner);

        $schemaVersion = $this->read('app/Modules/Venues/Models/HallSchemaVersion.php');
        static::assertStringContainsString('SchemaPrice::forStandingZone', $schemaVersion);
        static::assertStringContainsString('SchemaPrice::forRow', $schemaVersion);
        static::assertStringContainsString('SchemaPrice::seatOverride', $schemaVersion);

        $hallService = $this->read('app/Modules/Venues/Halls/Services/HallService.php');
        static::assertStringContainsString('SchemaPrice::forRow', $hallService);
        static::assertStringContainsString('SchemaPrice::ofNode', $hallService);

        $inventory = $this->read('app/Modules/Inventory/Services/InventoryService.php');
        static::assertStringContainsString('SchemaPrice::seatOverride', $inventory);

        $client = $this->read('resources/js/lib/hall.ts');
        static::assertStringContainsString('NODE_PRICE_KEYS', $client);
        static::assertStringContainsString('SEAT_PRICE_KEYS', $client);
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDir): array
    {
        return $this->files($relativeDir, 'php');
    }

    /** @return list<string> */
    private function scriptFiles(string $relativeDir): array
    {
        return $this->files($relativeDir, 'ts', 'vue');
    }

    /**
     * @return list<string>
     */
    private function files(string $relativeDir, string ...$extensions): array
    {
        $root = dirname(__DIR__, 2) . '/' . $relativeDir;
        $found = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /** Исходник без комментариев: литерал правила в пояснении не должен ловиться. */
    private function codeOnly(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }
}
