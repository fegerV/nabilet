<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Services;

use Illuminate\Http\Response;
use Nabilet\Core\Http\BrandPalette;
use Nabilet\Core\Http\SearchVerification;
use Nabilet\Core\Support\DataLayer;
use Nabilet\Core\Support\JsonLd;
use Nabilet\Core\Support\MetaTag;
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
 * ЭКРАНИРОВАНИЕ JSON-LD — В `Core\Support\JsonLd`. Раньше здесь лежала
 * собственная копия `safeJsonLd()`, и её докблок обещал выделить общий класс
 * «при появлении третьего потребителя». Третий (`Events\Http\EventPageShell`)
 * появился раньше, чем обещание было выполнено, поэтому правило теперь одно:
 * `JsonLd::escape()` для вставки внутрь готового тега и `JsonLd::script()` для
 * тега целиком. `robots` и коды верификации собирает `MetaTag`, `dataLayer` —
 * `DataLayer`.
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
                '{{robots}}' => MetaTag::named('robots', $page->robots),
                '{{jsonLd}}' => $jsonLd,
                // B-7: коды верификации поисковиков — владелец
                // `Core\Http\SearchVerification`. Сейчас редакционная страница
                // не имеет геопривязки, поэтому выводятся только коды.
                '{{headExtras}}' => SearchVerification::tags(),
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

        // `JsonLd::script()` сам возвращает пустую строку на `false` от
        // `json_encode()`: проверять это здесь второй раз незачем.
        return JsonLd::script($breadcrumb) . JsonLd::script($article);
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

        return DataLayer::push([
            'event' => 'page_view',
            'page_type' => 'page',
            'page_slug' => $slug,
            'page_path' => $path,
        ]);
    }
}
