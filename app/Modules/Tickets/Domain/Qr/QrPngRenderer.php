<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain\Qr;

/**
 * Матрица модулей → PNG (оттенки серого, 1 бит на пиксель).
 *
 * ПОЧЕМУ PNG, А НЕ SVG
 *
 * SVG-письмо красивее и легче, но Outlook рисует письма движком Word и SVG не
 * поддерживает вообще — картинка просто не появится. PNG понимают все, кто
 * вообще показывает картинки. Разница в размере несущественна: чёрно-белая
 * картинка без градиентов сжимается в разы.
 *
 * ПОЧЕМУ БЕЗ GD И IMAGICK
 *
 * Расширения может не быть на боевом сервере, а `imagepng()` ещё и требует
 * ресурс-дескриптор. Формат PNG для двух цветов собирается вручную: сигнатура,
 * `IHDR`, `IDAT` со сжатыми строками и `IEND`. Сжатие даёт встроенный
 * `gzcompress()` — он есть всегда.
 *
 * КАРТИНКА РИСУЕТСЯ В МАСШТАБЕ
 *
 * Один модуль = `$scale` пикселей, а не один пиксель: тогда картинку не нужно
 * растягивать средствами почтового клиента. Растягивание — единственный способ
 * получить мыло вместо кода: интерполяция размывает границы модулей, и сканер
 * перестаёт их различать.
 */
final class QrPngRenderer
{
    /** Модулей «тихой зоны» с каждой стороны. Без неё сканер не находит код. */
    public const DEFAULT_QUIET_ZONE = 4;

    /** Пикселей на модуль. */
    public const DEFAULT_SCALE = 4;

    private function __construct() {}

    /**
     * @param  array{size:int, rows:list<list<bool>>}  $matrix
     */
    public static function render(
        array $matrix,
        int $scale = self::DEFAULT_SCALE,
        int $quietZone = self::DEFAULT_QUIET_ZONE,
    ): string {
        $scale = max(1, $scale);
        $quietZone = max(0, $quietZone);

        $modules = $matrix['size'];
        $side = ($modules + 2 * $quietZone) * $scale;
        $rowBytes = intdiv($side + 7, 8);

        $raw = '';

        for ($y = 0; $y < $side; $y++) {
            $moduleY = intdiv($y, $scale) - $quietZone;

            // Первый байт строки — фильтр PNG, он всегда 0. Пиксели пишутся
            // ПОСЛЕ него: если писать в байт 0, фильтр затирается данными и
            // картинка разъезжается на один байт по всей строке.
            $line = "\x00" . str_repeat("\x00", $rowBytes);

            for ($x = 0; $x < $side; $x++) {
                $moduleX = intdiv($x, $scale) - $quietZone;

                $isDark = $moduleY >= 0 && $moduleY < $modules
                    && $moduleX >= 0 && $moduleX < $modules
                    && ($matrix['rows'][$moduleY][$moduleX] ?? false);

                // 1 бит на пиксель: 0 — чёрный, 1 — белый.
                if (! $isDark) {
                    $byteIndex = 1 + intdiv($x, 8);
                    $line[$byteIndex] = chr(ord($line[$byteIndex]) | (0x80 >> ($x % 8)));
                }
            }

            $raw .= $line;
        }

        return self::signature()
            . self::chunk('IHDR', pack('NNCCCCC', $side, $side, 1, 0, 0, 0, 0))
            . self::chunk('IDAT', gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    /**
     * Готовый `src` для `<img>`: подписанный payload остаётся в письме и не
     * уходит никуда — ни `fetch`, ни внешний `<img src="https://...">`.
     *
     * @param  array{size:int, rows:list<list<bool>>}  $matrix
     */
    public static function dataUri(
        array $matrix,
        int $scale = self::DEFAULT_SCALE,
        int $quietZone = self::DEFAULT_QUIET_ZONE,
    ): string {
        return 'data:image/png;base64,'
            . base64_encode(self::render($matrix, $scale, $quietZone));
    }

    private static function signature(): string
    {
        return "\x89PNG\r\n\x1a\n";
    }

    private static function chunk(string $type, string $payload): string
    {
        $body = $type . $payload;

        return pack('N', strlen($payload)) . $body . pack('N', crc32($body));
    }
}
