<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * CSS-цвет: единственный владелец правила «это цвет?».
 *
 * ЗАЧЕМ. Проверка hex-цвета была выписана в четырёх местах и успела разойтись:
 *
 *   StorefrontService::color()            `/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/`
 *   TicketPalette::relativeLuminance()    `/^#([0-9a-f]{3}|[0-9a-f]{6})$/`
 *   TicketPalette::color()                `/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/`
 *   TicketPdfRenderer::safeColor()        `/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/`
 *
 * Три копии из четырёх были регистрозависимыми, одна — нет. Живой ошибки это
 * пока не давало (каждый вызывающий приводил регистр сам), но цена копии не в
 * текущем расхождении, а в следующем: правку «разрешим ещё и 8-значный hex с
 * альфой» надо не забыть внести в четыре файла, причём один из них — рендерер
 * PDF, который проверяется глазами на распечатанном билете.
 *
 * Хуже была вторая копия — СПИСОК ИМЁН. `TicketPalette::NAMED_COLORS` знал
 * четыре слова, а `TicketPdfRenderer::safeColor()` держал свой список из пяти
 * (с `none`) и в докблоке обещал «как в TicketPalette». Здесь эти два набора
 * названы явно и по отдельности, потому что это разные вопросы:
 *
 *   NAMED        — цвет, для которого можно посчитать яркость;
 *   NON_COLOUR   — ключевое слово заливки, цвета в нём нет (`none` = не заливать).
 *
 * Класс намеренно чистый (без illuminate), чтобы не ломать
 * `tools/verify-purity.php`.
 */
final class CssColor
{
    /**
     * Имена, которые ведут себя как цвет: их принимает и палитра билета, и
     * рендерер PDF.
     *
     * @var list<string>
     */
    public const NAMED = ['black', 'white', 'transparent', 'inherit'];

    /**
     * Ключевые слова заливки, которые цветом НЕ являются.
     *
     * `none` — «не заливать». Палитра билета его не знает намеренно: ей нужен
     * цвет, чтобы посчитать контраст, а у `none` контраста нет. Рендерер PDF
     * его пропускает: это законное значение SVG-атрибута `fill`.
     *
     * @var list<string>
     */
    public const NON_COLOUR = ['none'];

    /**
     * Каноническая запись hex-цвета: `#rgb` или `#rrggbb`, в нижнем регистре.
     *
     * Валидный цвет возвращается как есть (короткая запись НЕ разворачивается —
     * это отдельный вопрос, см. `expand()`), невалидный — null. Вызывающий сам
     * решает, что делать с null: подставить кисть пресета, вернуть fallback или
     * отказать.
     *
     * Регистр принимается любой: `#FF5C22` — тот же цвет, что `#ff5c22`, и
     * отвергать его только потому, что организатор набрал заглавные, нельзя.
     */
    public static function normalizeHex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * `#abc` → `#aabbcc`.
     *
     * Нужно там, где цвет разбирается по каналам: `#abc` — это сокращение, и
     * `substr($hex, 0, 2)` на нём дало бы `ab` вместо `aa`. Ожидает уже
     * нормализованное значение (после `normalizeHex()`), шестизначное
     * возвращает без изменений.
     */
    public static function expand(string $hex): string
    {
        $hex = strtolower(trim($hex));

        if (strlen($hex) !== 4) {
            return $hex;
        }

        return '#'
            . $hex[1] . $hex[1]
            . $hex[2] . $hex[2]
            . $hex[3] . $hex[3];
    }

    /** Имя из `NAMED` (регистр не важен). */
    public static function isNamed(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::NAMED, true);
    }

    /** Имя из `NAMED` или ключевое слово из `NON_COLOUR`. */
    public static function isFillKeyword(string $value): bool
    {
        $value = strtolower(trim($value));

        return in_array($value, self::NAMED, true)
            || in_array($value, self::NON_COLOUR, true);
    }

    /**
     * Цвет для атрибута заливки: hex, имя цвета или ключевое слово `NON_COLOUR`.
     *
     * Возвращает каноническую запись в нижнем регистре либо null. Это самая
     * широкая из трёх проверок — та, что нужна рендереру PDF.
     */
    public static function normalizeFill(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hex = self::normalizeHex($value);

        if ($hex !== null) {
            return $hex;
        }

        $value = strtolower(trim($value));

        return self::isFillKeyword($value) ? $value : null;
    }
}
