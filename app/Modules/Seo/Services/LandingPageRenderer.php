<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Illuminate\Http\Response;
use Nabilet\Core\Http\BrandPalette;
use Nabilet\Core\Support\RuText;
use Nabilet\Modules\Seo\Domain\LandingPage;

/**
 * Рендер посадочной страницы в готовый HTML (аудит P-12).
 *
 * ПОЧЕМУ СОБСТВЕННЫЙ ДОКУМЕНТ, А НЕ ОБОЛОЧКА ВИТРИНЫ. Страницы события
 * (`EventPageController`) отдаются как `dist/index.html` с подставленными
 * метатегами: витрина — hash-SPA, и серверный HTML нужен там только как
 * «догоняющий» слой для роботов. Для посадочной страницы такой подход не
 * работает: адреса `/venue/…` и `/city/…` в SPA не существует вовсе, поэтому
 * после монтирования Vue пользователь увидел бы главную страницу каталога под
 * адресом площадки — то есть адрес и содержимое разошлись бы, а это ровно то,
 * за что поисковик понижает страницу.
 *
 * ЗАВИСИМОСТИ ОТ СБОРКИ НЕТ. Документ не подключает ни `dist/`, ни внешние
 * ресурсы: стили встроены, шрифты системные. Посадочные страницы — это точки
 * входа из поиска, и они обязаны открываться, даже если витрина не собрана;
 * сломанная сборка не должна превращать переход из выдачи в «сервер недоступен».
 * Тот же принцип, что у `NotFoundPage`.
 *
 * ВСЁ ЭКРАНИРУЕТСЯ. Название города, площадки и мероприятия вводит человек, и
 * они попадают в HTML. `htmlspecialchars(ENT_QUOTES)` применяется к каждому
 * значению на выходе, включая подписи ссылок; JSON-LD экранируется отдельно
 * (`safeJsonLd()`) — там `&quot;` сломал бы JSON.
 */
final class LandingPageRenderer
{
    public function __construct(
        private readonly StructuredDataService $structuredData,
    ) {}

    public function render(LandingPage $page): Response
    {
        return response($this->document($page))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function document(LandingPage $page): string
    {
        $canonical = $page->canonical();

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
            <meta property="og:type" content="website" />
            <meta property="og:locale" content="ru_RU" />
            <meta property="og:title" content="{{title}}" />
            <meta property="og:description" content="{{description}}" />
            <meta property="og:url" content="{{canonical}}" />
            <style>
              :root { color-scheme: dark; }
              * { box-sizing: border-box; }
              body {
                margin: 0;
                background: {{canvas}};
                color: {{content}};
                font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
              }
              .wrap { max-width: 880px; margin: 0 auto; padding: 32px 20px 64px; }
              .top { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
              .bar { width: 56px; height: 4px; border-radius: 999px; background: linear-gradient(90deg, {{brand}} 0%, {{accent}} 100%); }
              .top a { color: {{content}}; font-weight: 600; text-decoration: none; letter-spacing: .04em; }
              .top a:hover { text-decoration: underline; }
              nav.crumbs { margin-top: 28px; font-size: 14px; color: {{muted}}; }
              nav.crumbs ol { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
              nav.crumbs li::after { content: "›"; margin-left: 8px; color: {{muted}}; }
              nav.crumbs li:last-child::after { content: ""; }
              nav.crumbs a { color: {{muted}}; }
              h1 { margin: 20px 0 0; font-size: 32px; line-height: 1.2; }
              p.intro { margin: 12px 0 0; color: {{muted}}; max-width: 62ch; }
              h2 { margin: 40px 0 16px; font-size: 20px; }
              ul.events { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
              li.event { background: {{surface}}; border: 1px solid {{line}}; border-radius: 14px; padding: 16px 18px; }
              a.event-link { color: {{content}}; text-decoration: none; font-size: 18px; font-weight: 600; }
              a.event-link:hover { text-decoration: underline; }
              p.event-meta { margin: 6px 0 0; color: {{muted}}; font-size: 14px; }
              nav.pages { margin-top: 32px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
              nav.pages a, nav.pages span { font-size: 15px; }
              nav.pages a { color: {{content}}; }
              nav.pages .current { color: {{muted}}; }
              ul.related { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; }
              ul.related li { background: {{surface_raised}}; border: 1px solid {{line}}; border-radius: 999px; padding: 8px 14px; font-size: 14px; }
              ul.related a { color: {{content}}; text-decoration: none; }
              ul.related a:hover { text-decoration: underline; }
              ul.related span { color: {{muted}}; }
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
                  <p class="intro">{{intro}}</p>
                  <h2>Мероприятия</h2>
                  {{items}}
                  {{pagination}}
                  {{related}}
                </main>
                <footer>NABILET — билеты на события</footer>
              </div>
            </body>
            </html>
            HTML,
            [
                '{{title}}' => htmlspecialchars($page->title, ENT_QUOTES),
                '{{description}}' => htmlspecialchars($page->description, ENT_QUOTES),
                '{{canonical}}' => htmlspecialchars($canonical, ENT_QUOTES),
                '{{heading}}' => htmlspecialchars($page->heading, ENT_QUOTES),
                '{{intro}}' => htmlspecialchars($page->intro, ENT_QUOTES),
                '{{breadcrumbNav}}' => $this->breadcrumbNav($page),
                '{{items}}' => $this->items($page),
                '{{pagination}}' => $this->pagination($page),
                '{{related}}' => $this->related($page),
                '{{jsonLd}}' => $this->jsonLd($page),
                '{{canvas}}' => BrandPalette::CANVAS,
                '{{surface}}' => BrandPalette::SURFACE,
                '{{surface_raised}}' => BrandPalette::SURFACE_RAISED,
                '{{line}}' => BrandPalette::LINE,
                '{{content}}' => BrandPalette::CONTENT,
                '{{muted}}' => BrandPalette::MUTED,
                '{{brand}}' => BrandPalette::BRAND,
                '{{accent}}' => BrandPalette::ACCENT,
            ]
        );
    }

    /**
     * Видимая цепочка навигации.
     *
     * Из тех же данных, что и `BreadcrumbList` в JSON-LD: если бы навигацию
     * строил один список, а разметку — другой, они разошлись бы, и поисковик
     * увидел бы в разметке не то, что на странице. Расхождение видимых крошек и
     * разметки Google считает введением в заблуждение.
     */
    private function breadcrumbNav(LandingPage $page): string
    {
        $items = '';

        foreach ($page->breadcrumbs as $crumb) {
            $name = htmlspecialchars((string) $crumb['name'], ENT_QUOTES);
            $url = (string) ($crumb['url'] ?? '');

            $items .= $url === ''
                ? sprintf('<li>%s</li>', $name)
                : sprintf('<li><a href="%s">%s</a></li>', htmlspecialchars($url, ENT_QUOTES), $name);
        }

        return '<nav class="crumbs" aria-label="Навигационная цепочка"><ol>' . $items . '</ol></nav>';
    }

    private function items(LandingPage $page): string
    {
        $rows = '';

        foreach ($page->items as $item) {
            $meta = array_filter(
                [
                    RuText::dateTime($item['date'] ?? null),
                    $item['venue'] ?? null,
                    $item['price'] ?? null,
                ],
                static fn ($value): bool => $value !== null && trim((string) $value) !== '',
            );

            $rows .= sprintf(
                '<li class="event">'
                . '<a class="event-link" href="%s">%s</a>'
                . '%s'
                . '</li>',
                htmlspecialchars((string) $item['url'], ENT_QUOTES),
                htmlspecialchars((string) $item['title'], ENT_QUOTES),
                $meta === []
                    ? ''
                    : sprintf('<p class="event-meta">%s</p>', htmlspecialchars(implode(' · ', $meta), ENT_QUOTES)),
            );
        }

        return '<ul class="events">' . $rows . '</ul>';
    }

    /**
     * Ссылки пагинации.
     *
     * `rel="prev"`/`rel="next"` намеренно не выводятся: Google перестал их
     * учитывать, а Яндекс использует собственную разметку. Достаточно обычных
     * ссылок — робот обходит их и так, — а номер страницы в заголовке
     * (`LandingPageService::withPageSuffix()`) не даёт страницам пагинации стать
     * дублями друг друга.
     */
    private function pagination(LandingPage $page): string
    {
        if ($page->lastPage <= 1) {
            return '';
        }

        $parts = [];

        if ($page->page > 1) {
            $parts[] = sprintf(
                '<a href="%s">← Назад</a>',
                htmlspecialchars($page->pageUrl($page->page - 1), ENT_QUOTES),
            );
        }

        $parts[] = sprintf(
            '<span class="current">Страница %d из %d</span>',
            $page->page,
            $page->lastPage,
        );

        if ($page->page < $page->lastPage) {
            $parts[] = sprintf(
                '<a href="%s">Вперёд →</a>',
                htmlspecialchars($page->pageUrl($page->page + 1), ENT_QUOTES),
            );
        }

        return '<nav class="pages" aria-label="Страницы">' . implode('', $parts) . '</nav>';
    }

    /**
     * Перелинковка на соседние коллекции.
     *
     * НЕ УКРАШЕНИЕ. Посадочная страница без исходящих ссылок — тупик: робот,
     * попавший на неё из выдачи, не находит соседние города и площадки, а
     * пользователь не может продолжить выбор. Ссылки ведут на коллекции, где
     * события действительно есть, поэтому каждая из них отвечает 200.
     */
    private function related(LandingPage $page): string
    {
        if ($page->related === []) {
            return '';
        }

        $items = '';

        foreach ($page->related as $link) {
            $items .= sprintf(
                '<li><a href="%s">%s</a> <span>%d</span></li>',
                htmlspecialchars((string) $link['url'], ENT_QUOTES),
                htmlspecialchars((string) $link['label'], ENT_QUOTES),
                (int) $link['count'],
            );
        }

        return '<h2>Смотрите также</h2><ul class="related">' . $items . '</ul>';
    }

    /**
     * JSON-LD документа: BreadcrumbList, ItemList и разметка самой сущности.
     *
     * Три независимых блока, а не один `@graph`. Так их читают по отдельности и
     * валидатор Google, и Яндекс.Вебмастер, и ошибка в одном не обнуляет другой.
     * Склейка в `@graph` экономила бы тег, но связала бы разметки, у которых
     * нет общих полей.
     *
     * ТРЕТИЙ БЛОК ПРИХОДИТ ГОТОВЫМ (`$page->entity`) и рендер не знает, что в
     * нём: `Place` на странице площадки, `Organization` на странице организатора
     * или ничего. Знание о том, из каких колонок собирается разметка, осталось в
     * `StructuredDataService`; здесь только вставка. Иначе шаблон страницы
     * пришлось бы править при каждом новом типе страницы — а он для того и
     * вынесен в `strtr()`, чтобы правки текста не трогали логику.
     *
     * Пустой `ItemList` не выводится: `<script>` без элементов валидатор
     * показывает как ошибку «expected a value», а не как пустой список. Крошки
     * выводятся всегда — они непусты по построению. Разметка сущности
     * пропускается по тому же признаку, что и `ItemList`.
     */
    private function jsonLd(LandingPage $page): string
    {
        $breadcrumb = json_encode(
            $this->structuredData->generateBreadcrumbList($page->breadcrumbs),
        );

        $blocks = $this->script($breadcrumb);

        if ($page->items !== []) {
            $itemList = json_encode($this->structuredData->generateItemList(array_map(
                static fn (array $item): array => [
                    'name' => (string) $item['title'],
                    'url' => (string) $item['url'],
                ],
                $page->items,
            )));

            $blocks .= $this->script($itemList);
        }

        if ($page->entity !== null && $page->entity !== []) {
            $blocks .= $this->script(json_encode($page->entity));
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
     * JSON-LD, безопасный для вставки внутрь `<script>`.
     *
     * Экранируются только `<` и `>` — в шестнадцатеричные последовательности.
     * Это валидный JSON (`\u003C` разбирается парсером как `<`), но браузер
     * больше не увидит `</script>` внутри строкового значения и не оборвёт тег.
     * `htmlspecialchars()` здесь неприменим: `&quot;` сломал бы JSON целиком.
     *
     * Название площадки или мероприятия приходит от администратора, поэтому
     * `</script>` в нём — не гипотетический сценарий, а обычная опечатка в
     * описании.
     */
    private function safeJsonLd(string $jsonLd): string
    {
        return str_replace(['<', '>'], ['\u003C', '\u003E'], $jsonLd);
    }
}
