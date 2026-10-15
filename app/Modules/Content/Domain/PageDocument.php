<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Domain;

/**
 * Готовая редакционная страница CMS (стратегия SEO, B-2).
 *
 * ЗАЧЕМ ТИПИЗИРОВАННЫЙ ОБЪЕКТ, А НЕ МАССИВ. Тот же аргумент, что у
 * `Seo\Domain\LandingPage`: позиционная сборка строк `sprintf` с восемью
 * `%s` однажды уже сдвинула все аргументы — теги уехали в чужие места, и
 * ошибки не было. Здесь поля именованные, и рендер не может перепутать
 * `title` с `description`.
 *
 * ЧИСТЫЙ (без Eloquent, без `config()`, без I/O). Этого требует purity-гейт
 * для каталога `Domain` внутри модулей (см. `tools/verify-purity.php`), и это
 * же делает объект пригодным для тестов без БД.
 *
 * СОДЕРЖИМОЕ ХРАНИТСЯ КАК HTML-СТРОКА. Сервис выше (`PageService`) выбирает
 * формат один раз — HTML или Markdown — и хранит результат в этом поле, чтобы
 * рендер не знал о парсерах. Если бы парсер жил в рендере, любая правка
 * формата правкой в двух местах (как было у `renderSpaShell()`).
 *
 * `organizationName` НЕ ОБЯЗАТЕЛЬНО, но нужно для JSON-LD `publisher.name`:
 * если страница привязана к организации, поле заполнено и попадает в
 * `Article.publisher`, иначе — отсутствует и `pruneEmpty()` выкидывает ключ.
 *
 * @property-read string $canonical канонический адрес страницы — через
 *           `route('content.page', ['slug' => $this->slug])`. Вынесено из
 *           рендера, чтобы адрес строился в одном месте: рендерер тогда не
 *           может «забыть» `?page` или `?ref` и не разойтись с sitemap'ом.
 */
final class PageDocument
{
    /**
     * @param  string  $slug             латинский slug из URL
     * @param  string  $title            H1 и `<title>`
     * @param  string  $description      `<meta name="description">` (≤160 символов)
     * @param  string  $contentHtml      тело документа, готовое к вставке в HTML
     * @param  ?string $publishedAt      ISO8601 момент публикации (для JSON-LD `datePublished`)
     * @param  ?string $updatedAt        ISO8601 момент правки (для JSON-LD `dateModified`)
     * @param  ?string $robots           явный robots из БД (`seo_meta.robots` или `pages.robots`),
     *                                   `null` означает «не задано» и рендер не выводит `<meta>`
     * @param  ?string $organizationName имя организации-издателя для `Article.publisher`,
     *                                   `null` означает «общая страница» и `Article.publisher` пропускается
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly string $contentHtml,
        public readonly ?string $publishedAt,
        public readonly ?string $updatedAt,
        public readonly ?string $robots = null,
        public readonly ?string $organizationName = null,
    ) {}

    /**
     * Канонический адрес страницы — единственное место, где он строится.
     *
     * Бросает `RouteNotFoundException`, если маршрут не зарегистрирован: до
     * `routes/web.php` это означает, что контроллер живёт, а маршрут — нет, и
     * страница в принципе не имеет публичного адреса. Здесь это падение
     * корректнее, чем молчаливый пустой URL: рендер не сможет «забыть»
     * `?page` и не разойдётся с sitemap'ом.
     *
     * `function_exists('route')` — КОНТРАКТНОЕ ОБЯЗАТЕЛЬСТВО, А НЕ
     * ОПТИМИЗАЦИЯ. Purity-гейт (`tools/verify-purity.php`) разрешает
     * фреймворковый хелпер только если файл доказал наличие фреймворка
     * через `function_exists()`. Без этой проверки гейт красный, и тесты
     * `tests/Unit/...` без `vendor/` не смогли бы загрузить `PageDocument`.
     */
    public function canonical(): string
    {
        if (function_exists('route')) {
            return route('content.page', ['slug' => $this->slug]);
        }

        // Fallback для окружений без Laravel (например, `tests/Unit` под
        // `tests/run.php`). Адрес собирается по той же схеме, что
        // маршрут `content.page`, чтобы тесты Domain могли проверить форму
        // канонического адреса без роутера.
        return '/page/' . $this->slug;
    }
}