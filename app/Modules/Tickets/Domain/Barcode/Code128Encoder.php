<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain\Barcode;

/**
 * Локальный кодировщик CODE128 (subset B) для штрихкода билета.
 *
 * Никаких сторонних сервисов и зависимостей: номер билета — служебные данные,
 * а подписанный QR остаётся единственным пропуском. Штрихкод НЕ принимается
 * контролёром как средство входа — он информационный (см. docs/audit report),
 * но он настоящий扫码-символ с контрольной суммой и тихой зоной, а не заглушка.
 *
 * Формат: START-B → символы ASCII 32..126 → CHECKSUM → STOP.
 */
final class Code128Encoder
{
    /** Ширины полос/пробелов (чередуются, начинаются с полосы). */
    private const PATTERNS = [
        212222, 222122, 222221, 121223, 121322, 131222, 122213, 122312, 132212, 221213,
        221312, 231212, 112232, 122132, 122231, 113222, 123122, 123221, 223211, 221132,
        221231, 213212, 223112, 312131, 311222, 321122, 321221, 312212, 322112, 322211,
        212123, 212321, 232121, 111323, 131123, 131321, 112313, 132113, 132311, 211313,
        231113, 231311, 112133, 112331, 132131, 113123, 113321, 133121, 313121, 211331,
        231131, 213113, 213311, 213131, 311123, 311321, 331121, 312113, 312311, 332111,
        314111, 221411, 431111, 111224, 111422, 121124, 121421, 141122, 141221, 112214,
        112412, 122114, 122411, 142112, 142211, 241211, 221114, 413111, 241112, 134111,
        111242, 121142, 121241, 114212, 124112, 124211, 411212, 421112, 421211, 212141,
        214121, 412121, 111143, 111341, 131141, 114113, 114311, 411113, 411311, 113141,
        114131, 311141, 411131, 211412, 211214, 211232, 2331112,
    ];

    private const START_B = 104;
    private const STOP = 106;
    /** Рекомендованная тихая зона — 10 модулей с каждой стороны. */
    private const QUIET_ZONE = 10;

    public static function encode(string $value): string
    {
        // CODE128-B кодирует ASCII 32..126. Всё остальное отбрасываем, чтобы
        // символ всегда был валидным (пользовательский текст не ломает билет).
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        if ($value === '') {
            throw new \InvalidArgumentException('Barcode value is empty.');
        }

        $codes = [self::START_B];
        $sum = self::START_B;
        $position = 1;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($value[$i]) - 32;
            $codes[] = $code;
            $sum += $code * $position;
            $position++;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return implode('', array_map(
            static fn (int $code): string => (string) self::PATTERNS[$code],
            $codes,
        ));
    }

    /** @return list<array{0:int,1:int}> полосы в модулях: [ширина, смещение] */
    public static function bars(string $value): array
    {
        $pattern = self::encode($value);
        $bars = [];
        $x = self::QUIET_ZONE;
        $isBar = true;
        foreach (str_split($pattern) as $digit) {
            $width = (int) $digit;
            if ($isBar) {
                $bars[] = [$x, $width];
            }
            $x += $width;
            $isBar = ! $isBar;
        }

        return $bars;
    }

    public static function modules(string $value): int
    {
        $total = self::QUIET_ZONE * 2;
        foreach (str_split(self::encode($value)) as $digit) {
            $total += (int) $digit;
        }

        return $total;
    }

    /**
     * SVG-представление с тихой зоной и сохранением пропорций.
     */
    public static function svg(string $value, float $width, float $height, string $color = '#111827'): string
    {
        $modules = self::modules($value);
        $module = max(1.0, min($width / $modules, $height / 60.0));
        $bars = '';
        foreach (self::bars($value) as [$offset, $barWidth]) {
            $x = round($offset * $module, 3);
            $w = round($barWidth * $module, 3);
            $bars .= sprintf('<rect x="%F" y="0" width="%F" height="%F" fill="%s"/>', $x, $w, $module * 60, $color);
        }
        $svgW = round($modules * $module, 3);
        $svgH = round($module * 60, 3);

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%F" height="%F" viewBox="0 0 %F %F">%s</svg>',
            $svgW, $svgH, $svgW, $svgH, $bars,
        );
    }

    public static function dataUri(string $value, float $width, float $height, string $color = '#111827'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($value, $width, $height, $color));
    }
}
