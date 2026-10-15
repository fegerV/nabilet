<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Services;

use Illuminate\Http\Response;
use Nabilet\Core\Http\BrandPalette;
use Nabilet\Modules\Content\Domain\PageDocument;
use Nabilet\Modules\Seo\Services\StructuredDataService;

/**
 * Рендер редакционной страницы CMS в готовый HTML (стратегия SEO, B-2).
 *
 * ПОЧЕМУ СОБСТВЕННЫЙ ДОКУМЕНТ, А НЕ ОБОЛОЧКА ВИТРИНЫ. Та же причина, что у
 * `Seo\Services\LandingPageRenderer`: витрина — hash-SPA, и подстановка
 * серверного HTML под её оболочку оставила бы после монтирования Vue пустую
 * страницу под адресом `/page/{slug}`. Для редакционной страницы это особенно
 * заметно: она должна работать без JS, чтобы её прочитал робот и человек с
 * отключёнными скриптами.
 *
 * ЗАВИСИМОСТИ ОТ СБОРКИ НЕТ. Документ не подключает `dist/` и внешние ресурсы:
 * стили встроены, шрифты системные. Сломанная сборка не должна превращать
 * редакционную страницу в «сервер недоступен».
 *
 * `safeJsonLd()` ОБЯЗАТЕЛЕН. Контент пишет администратор, и `</script>` в
 * статье — обычная опечатка: `<script>document.write('foo');</script>`
 * обрывает встроенный JSON-LD и портит всю разметку. Преобразование `<`/`>` в
 * `\u003C`/`\u003E` оставляет JSON валидным (`\u003C` парсер читает как `<`),
 * но браузер уже не увидит закрывающий тег.
 *
 * `robots` ВЫВОДИТСЯ ТОЛЬКО КОГДА ЗАДАНО. Сейчас `PageDocument::$robots`
 * всегда `null` (колонки в `pages` нет), и `<meta name="robots">` не
 * появляется в HTML — дефолт «index, follow» действует по умолчанию.
 */
final class PageRenderer
{
    public function __construct(
        private readonly StructuredDataService $structuredData,
    ) {}

    public function render(PageDocument $page): Response
    {
        return response($this->document($page))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function document(PageDocument $page): string
    {
        $canonical = $page->canonical();
        $jsonLd = $this->jsonLd($page);

        return strtr(
            <<<'HTML'
            <!DOCTYPE html>
            <html lang="ru">
            <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>{{title}}</title>
            <meta name="description" content="{{description}}" />
            <link rel="canonical" href="{{canonical}}" />
            {{robots}}
            <meta property="og:type" content="article" />
            <meta property="og:locale" content="ru_RU" />
            <meta property="og:title" content="{{title}}" />
            <meta property="og:description" content="{{description}}" />
            <meta property="og:url" content="{{canonical}}" />
            {{headExtras}}
            <style>
              :root { color-scheme: dark; }
              * { box-sizing: border-box; }
              body {
                margin: 0;
                background: {{canvas}};
                color: {{content}};
                font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
              }
              .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 64px; }
              .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
              .bar { width: 56px; height: 4px; border-radius: 999px; background: linear-gradient(90deg, {{brand}} 0%, {{accent}} 100%); }
              .top a { color: {{content}}; font-weight: 600; text-decoration: none; letter-spacing: .04em; }
              .top a:hover { text-decoration: underline; }
              nav.crumbs { margin-top: 28px; font-size: 14px; color: {{muted}}; }
              nav.crumbs ol { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
              nav.crumbs li::after { content: "›"; margin-left: 8px; color: {{muted}}; }
              nav.crumbs li:last-child::after { content: ""; }
              nav.crumbs a { color: {{muted}}; }
              h1 { margin: 20px 0 0; font-size: 32px; line-height: 1.25; }
              .article-body { margin-top: 28px; font-size: 17px; line-height: 1.7; }
              .article-body p { margin: 0 0 1em; }
              .article-body h2 { margin: 1.6em 0 .5em; font-size: 22px; }
              .article-body h3 { margin: 1.4em 0 .4em; font-size: 18px; }
              .article-body ul, .article-body ol { padding-left: 1.4em; }
              .article-body a { color: {{brand}}; }
              .article-body code {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.92em;
                background: {{surface}};
                padding: 1px 6px;
                border-radius: 4px;
              }
              .article-body pre {
                background: {{surface}};
                border: 1px solid {{line}};
                border-radius: 10px;
                padding: 14px 16px;
                overflow-x: auto;
              }
              .article-body blockquote {
                margin: 1em 0;
                padding: 6px 16px;
                border-left: 3px solid {{brand}};
                color: {{muted}};
              }
              footer { margin-top: 56px; font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: {{muted}}; }
            </style>
            {{jsonLd}}
            </head>
            <body>
              <div class="wrap">
                <div class="top">
                  <div class="bar" aria-hidden="true"></div>
                  <a href="/">Афиша</a>
                </div>
                {{breadcrumbNav}}
                <main>
                  <h1>{{heading}}</h1>
                  <div class="article-body">{{articleBody}}</div>
                </main>
                <footer>NABILET — билеты на события</footer>
              </div>
              {{dataLayer}}
            </body>
            </html>
            HTML,
            [
                '{{title}}' => htmlspecialchars($page->title, ENT_QUOTES),
                '{{description}}' => htmlspecialchars($page->description, ENT_QUOTES),
                '{{canonical}}' => htmlspecialchars($canonical, ENT_QUOTES),
                '{{heading}}' => htmlspecialchars($page->title, ENT_QUOTES),
                '{{articleBody}}' => $page->contentHtml,
                '{{breadcrumbNav}}' => $this->breadcrumbNav($page),
                '{{robots}}' => $this->robotsMeta($page),
                '{{jsonLd}}' => $jsonLd,
                // B-7: коды верификации поисковиков — общая логика со
                // `LandingPageRenderer::headExtras()` и `EventPageController::
                // seoVerificationAndGeoTags()`. Сейчас редакционная страница
                // не имеет геопривязки, поэтому выводятся только коды.
                '{{headExtras}}' => $this->headExtras(),
                // B-7: редакционная страница — собственный `page_type: 'page'`
                // в аналитике, отличающий её от посадочных и событий.
                '{{dataLayer}}' => $this->dataLayerSnippet($page->slug, $canonical),
                '{{canvas}}' => BrandPalette::CANVAS,
                '{{surface}}' => BrandPalette::SURFACE,
                '{{brand}}' => BrandPalette::BRAND,
                '{{accent}}' => BrandPalette::ACCENT,
                '{{content}}' => BrandPalette::CONTENT,
                '{{muted}}' => BrandPalette::MUTED,
                '{{line}}' => BrandPalette::LINE,
            ]
        );
    }

    /**
     * Хлебные крошки страницы: «Главная → <title>».
     *
     * Промежуточных уровней нет: редакционная страница не лежит внутри
     * посадочного раздела, и выдуманный путь в BreadcrumbList хуже честного
     * «Главная → О компании». Когда у страниц появятся категории (например,
     * «Гайды», «О компании»), сюда добавится третий уровень.
     */
    private function breadcrumbNav(PageDocument $page): string
    {
        return '<nav class="crumbs" aria-label="Навигационная цепочка">'
            . '<ol>'
            . '<li><a href="' . htmlspecialchars(url('/'), ENT_QUOTES) . '">Главная</a></li>'
            . '<li>' . htmlspecialchars($page->title, ENT_QUOTES) . '</li>'
            . '</ol>'
            . '</nav>';
    }

    /**
     * `<meta name="robots">` — выводится только когда `PageDocument::$robots`
     * непуст. Пустое — дефолт «index, follow», и тег не нужен: он бы дублировал
     * поведение, а не сообщал роботу новое.
     */
    private function robotsMeta(PageDocument $page): string
    {
        if ($page->robots === null || trim($page->robots) === '') {
            return '';
        }

        return sprintf(
            '<meta name="robots" content="%s" />',
            htmlspecialchars($page->robots, ENT_QUOTES),
        );
    }

    /**
     * JSON-LD Article для редакционной страницы.
     *
     * `pruneEmpty()` в `StructuredDataService::generateArticle()` сам решает,
     * какие поля убрать: `author` без `name` не имеет смысла, `publisher`
     * без `name` — тоже. Это позволяет рендереру не знать, что именно
     * передавать: пустые строки превращаются в отсутствие ключа.
     *
     * `BreadcrumbList` строится из того же списка, что виден в `<nav>`,
     * — иначе робот увидит в разметке не то, что показано человеку.
     * Расхождение видимых крошек и разметки Google считает введением в
     * заблуждение.
     */
    private function jsonLd(PageDocument $page): string
    {
        $breadcrumb = json_encode(
            $this->structuredData->generateBreadcrumbList([
                ['name' => 'Главная', 'url' => url('/')],
                ['name' => $page->title, 'url' => $page->canonical()],
            ]),
        );

        $article = json_encode(
            $this->structuredData->generateArticle([
                'headline' => $page->title,
                'description' => $page->description,
                'url' => $page->canonical(),
                'datePublished' => $page->publishedAt,
                'dateModified' => $page->updatedAt,
                'publisherName' => $page->organizationName,
            ]),
        );

        $blocks = $this->script($breadcrumb);

        if ($article !== false) {
            $blocks .= $this->script($article);
        }

        return $blocks;
    }

    private function script(string|false $json): string
    {
        if (! is_string($json) || trim($json) === '') {
            return '';
        }

        return '<script type="application/ld+json">' . $this->safeJsonLd($json) . '</script>';
    }

    /**
     * Экранирование, безопасное для вставки внутрь `<script>`.
     *
     * Преобразуются только `<` и `>` — в `\u003C` и `\u003E`. Это валидный JSON
     * (парсер читает `\u003C` как `<`), но браузер уже не увидит `</script>`
     * внутри строкового значения и не оборвёт тег. `htmlspecialchars()` здесь
     * неприменим: `&quot;` сломал бы JSON целиком.
     *
     * Та же операция живёт в `LandingPageRenderer::safeJsonLd()` — копия
     * осознанная: у двух рендереров разные сервисы-зависимости, и общего
     * трейта/класса они пока не имеют. При появлении ТРЕТЬЕГО потребителя —
     * выделить в `Nabilet\Core\Support\JsonLd::escape()`.
     */
    private function safeJsonLd(string $jsonLd): string
    {
        return str_replace(['<', '>'], ['\u003C', '\u003E'], $jsonLd);
    }

    /**
     * Серверный `dataLayer.push` для редакционной страницы (стратегия SEO, B-7).
     *
     * `page_type: 'page'` фиксирован: отличает редакционные страницы CMS
     * от посадочных (`venue`, `city`, ...) и событий (`event`). `page_slug`
     * пробрасывается, чтобы в отчёте по типу страницы можно было отделить
     * FAQ от «О компании», не разбирая URL на стороне GA.
     *
     * `page_path` — относительный путь без query-string; для редакционных
     * страниц query-string не предусмотрен, но `parse_url` всё равно
     * отрезает его на случай, если будущая версия введёт пагинацию.
     */
    private function dataLayerSnippet(string $slug, string $canonical): string
    {
        $path = parse_url($canonical, PHP_URL_PATH) ?: ('/page/' . $slug);

        $payload = json_encode([
            'event' => 'page_view',
            'page_type' => 'page',
            'page_slug' => $slug,
            'page_path' => $path,
        ], JSON_UNESCAPED_UNICODE);

        if (! is_string($payload)) {
            return '';
        }

        return sprintf(
            "<script>(function(w){w.dataLayer=w.dataLayer||[];w.dataLayer.push(%s);})(window);</script>",
            $payload,
        );
    }

    /**
     * Коды верификации Яндекс/Google в `<head>` (стратегия SEO, B-7).
     *
     * Те же значения `config('nabilet.seo.verification.*')`, что и в
     * `LandingPageRenderer::headExtras()` и `EventPageController::
     * seoVerificationAndGeoTags()`: подтверждение прав на сайт ведётся по
     * одному домену, и код одинаков на всех страницах.
     *
     * Пустое значение → тег НЕ выводится. Это важно: пустой
     * `<meta name="yandex-verification" content="">` валидатор Яндекс.Вебмастера
     * читает как ошибку, и подтверждение прав не сработает.
     */
    private function headExtras(): string
    {
        $tags = [];

        $yandexVerification = trim((string) (config('nabilet.seo.verification.yandex') ?? ''));
        if ($yandexVerification !== '') {
            $tags[] = sprintf(
                '<meta name="yandex-verification" content="%s" />',
                htmlspecialchars($yandexVerification, ENT_QUOTES),
            );
        }

        $googleVerification = trim((string) (config('nabilet.seo.verification.google') ?? ''));
        if ($googleVerification !== '') {
            $tags[] = sprintf(
                '<meta name="google-site-verification" content="%s" />',
                htmlspecialchars($googleVerification, ENT_QUOTES),
            );
        }

        return $tags === [] ? '' : implode('', $tags);
    }
}