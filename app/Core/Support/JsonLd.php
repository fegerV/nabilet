<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * JSON-LD в том виде, в каком он попадает в HTML — единственный владелец правила.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Правило «экранировать `<` и `>` перед вставкой JSON
 * внутрь `<script>`» стояло в трёх рендерерах: `Content\Services\PageRenderer`,
 * `Seo\Services\LandingPageRenderer` и `Events\Http\EventPageShell`. Докблок
 * `PageRenderer::safeJsonLd()` прямо оговаривал: «копия осознанная… при
 * появлении ТРЕТЬЕГО потребителя — выделить в `Nabilet\Core\Support\JsonLd::escape()`».
 * Третий потребитель к тому моменту уже существовал — `EventPageShell`, — и
 * условие выполнилось незаметно для всех трёх.
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. Разметку собирает `StructuredDataService` из
 * названий, которые вводит администратор. `</script>` в описании мероприятия —
 * обычная опечатка, а не гипотетический сценарий: без экранирования он
 * закрывает тег, и остаток JSON оказывается текстом страницы. `htmlspecialchars()`
 * здесь неприменим — `&quot;` сломал бы JSON целиком, поэтому преобразуются
 * ровно два символа, и преобразование обязано быть одинаковым везде: страница
 * события, посадочная и редакционная — один и тот же домен и один и тот же
 * валидатор.
 *
 * ЧИСТЫЙ КЛАСС. Ни `vendor/`, ни I/O: только `str_replace` и `trim`. Поэтому
 * живёт в `Core/Support`, рядом с `CssColor` и `DatabaseId`, и проверяется
 * dependency-free раннером.
 */
final class JsonLd
{
    /**
     * Экранирование, безопасное для вставки внутрь `<script>`.
     *
     * `<` и `>` превращаются в `\u003C`/`\u003E`. Это валидный JSON — парсер
     * читает `\u003C` как `<`, — но браузер уже не увидит закрывающий тег
     * внутри строкового значения.
     */
    public static function escape(string $json): string
    {
        return str_replace(['<', '>'], ['\u003C', '\u003E'], $json);
    }

    /**
     * Готовый `<script type="application/ld+json">` — или пустая строка.
     *
     * ПУСТОЙ БЛОК НЕ ВЫВОДИТСЯ. `<script type="application/ld+json"></script>`
     * валидатор Google показывает как ошибку «expected a value», а не как
     * «разметки нет», поэтому отсутствие данных обязано давать отсутствие тега,
     * а не пустой тег.
     *
     * `mixed`, а не `string|false`: вызывающие получают результат
     * `json_encode()` (который при ошибке даёт `false`) и значения из массивов
     * без объявленного типа. Этот метод — нормализатор, и проверка `is_string`
     * здесь единственная.
     */
    public static function script(mixed $json): string
    {
        if (! is_string($json) || trim($json) === '') {
            return '';
        }

        return '<script type="application/ld+json">' . self::escape($json) . '</script>';
    }
}
