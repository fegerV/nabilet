<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Один `<meta>`-тег — единственный владелец правила «пустое значение не выводится».
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Одна и та же тройка строк
 * (`sprintf` + `htmlspecialchars(ENT_QUOTES)` + проверка на пустоту) была
 * выписана тринадцать раз в трёх рендерерах и одном контроллере. Проверка
 * пустоты при этом не косметическая: пустой
 * `<meta name="yandex-verification" content="">` — это утверждение «код
 * подтверждения есть, но он пустой», и Яндекс.Вебмастер читает его как ошибку,
 * а не как «тега нет». Поэтому «нет значения» обязано означать «нет тега» — и
 * это правило должно быть одно, иначе один из рендереров рано или поздно
 * выведет пустой тег.
 *
 * `trim()` — часть правила, а не украшение: значение приходит из `config()` или
 * из формы, и `' '` в настройке верификации не должно превращаться в тег с
 * пробелом внутри.
 *
 * `mixed`, а не `?string`: вызывающие передают значения из `array<string, mixed>`
 * (`EventPageShell`) и nullable-поля value object (`LandingPage`). Метод и есть
 * нормализатор — массив или объект здесь означают ошибку сборки данных, и
 * молчаливое «Array» в `<meta content>` хуже отсутствия тега.
 */
final class MetaTag
{
    /**
     * `<meta name="…" content="…" />` — или пустая строка.
     *
     * `name` — для стандартных мета-тегов (`robots`, `yandex-verification`,
     * `geo.region`). Для Open Graph нужен `property`, см. `property()`.
     */
    public static function named(string $name, mixed $content): string
    {
        return self::render('name', $name, $content);
    }

    /**
     * `<meta property="…" content="…" />` — или пустая строка.
     *
     * Open Graph и Twitter Cards читают `property`, а не `name`; перепутать их
     * значит получить тег, который парсер соцсети просто не увидит.
     */
    public static function property(string $property, mixed $content): string
    {
        return self::render('property', $property, $content);
    }

    /**
     * Значение, пригодное для атрибута: непустая строка после `trim()`, иначе null.
     *
     * Отдельным методом, потому что нужен и там, где тег собирается не целиком
     * (`og:image` с дополнительными `og:image:alt`/`og:image:width`).
     */
    public static function value(mixed $content): ?string
    {
        if ($content === null || is_bool($content) || ! is_scalar($content)) {
            return null;
        }

        $value = trim((string) $content);

        return $value === '' ? null : $value;
    }

    private static function render(string $attribute, string $key, mixed $content): string
    {
        $value = self::value($content);

        if ($value === null) {
            return '';
        }

        return sprintf(
            '<meta %s="%s" content="%s" />',
            $attribute,
            $key,
            htmlspecialchars($value, ENT_QUOTES),
        );
    }
}
