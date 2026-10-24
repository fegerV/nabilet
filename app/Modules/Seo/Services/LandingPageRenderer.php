<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Illuminate\Http\Response;
use Nabilet\Core\Http\BrandPalette;
use Nabilet\Core\Http\SearchVerification;
use Nabilet\Core\Support\DataLayer;
use Nabilet\Core\Support\JsonLd;
use Nabilet\Core\Support\MetaTag;
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
 * (`Core\Support\JsonLd::escape()`) — там `&quot;` сломал бы JSON.
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
            {{headExtras}}
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
              ul.events--archive li.event { background: transparent; }
              ul.events--archive a.event-link { font-weight: 500; color: {{muted}}; }
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
                  {{items}}
                  {{pagination}}
                  {{related}}
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
                '{{heading}}' => htmlspecialchars($page->heading, ENT_QUOTES),
                '{{intro}}' => htmlspecialchars($page->intro, ENT_QUOTES),
                '{{breadcrumbNav}}' => $this->breadcrumbNav($page),
                '{{items}}' => $this->items($page),
                '{{pagination}}' => $this->pagination($page),
                '{{related}}' => $this->related($page),
                '{{jsonLd}}' => $this->jsonLd($page),
                // Дополнительные мета-теги: коды верификации поисковиков и
                // региональные `geo.*` (стратегия SEO, B-7).
                '{{headExtras}}' => $this->headExtras($page),
                // dataLayer.push для GTM/GA4/Яндекс.ТэгМенеджер. Кладётся в
                // конец body, чтобы не блокировать парсинг `</head>` и не
                // задерживать отрисовку первой страницы.
                '{{dataLayer}}' => $this->dataLayerSnippet($page->pageType, $canonical),
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
     * Дополнительные `<meta>`-теги для `<head>`: верификация Яндекс/Google и
     * региональная привязка (`geo.*`, стратегия SEO, B-7).
     *
     * ПУСТЫЕ ЗНАЧЕНИЯ НЕ ВЫВОДЯТСЯ. Это не косметика: пустой
     * `<meta name="yandex-verification" content="">` — это утверждение
     * «код подтверждения есть, но он пустой», и валидатор Яндекс.Вебмастера
     * трактует такое поле как ошибку. Поэтому теги собираются только при
     * наличии данных.
     *
     * `geo.position` выводится СТРОГО в формате «lat;lng» (спецификация ICBM);
     * Яндекс ждёт точку с запятой между координатами. Если хотя бы одной
     * координаты нет, LandingPage не передаст `geoPosition`, и тег просто не
     * появится — без полу-валидного `geo.position`.
     *
     * Порядок тегов внутри `<head>` детерминирован, потому что так удобнее
     * сравнивать вывод в тестах: сначала верификация (опционально), потом geo.
     */
    private function headExtras(LandingPage $page): string
    {
        // Коды верификации — владелец `Core\Http\SearchVerification`: они
        // одинаковы на всех страницах домена, и их же выводят редакционные
        // страницы и страницы событий.
        $tags = SearchVerification::tagList();

        // Геопривязка — через nullable-поля LandingPage: `$page->geoRegion`
        // и т. д. могут быть `null` (город, категория, артист), и пустую
        // строку мы здесь НЕ должны подменять выдуманным значением.
        // Пустое значение → тега нет: правило живёт в `MetaTag`.
        foreach ([
            'geo.region' => $page->geoRegion,
            'geo.placename' => $page->geoPlacename,
            'geo.position' => $page->geoPosition,
        ] as $name => $value) {
            $tag = MetaTag::named($name, $value);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags === [] ? '' : implode("\n            ", $tags);
    }

    /**
     * Инициализация `dataLayer` для GTM/GA4/Яндекс.ТэгМенеджер (B-7).
     *
     * Посадочная страница — серверный HTML без JS-бандла витрины, и у неё нет
     * своего вызова `ym(...)` или `gtag(...)`. Чтобы аналитика по типу страницы
     * (`page_view` с `page_type`) работала наравне с событиями SPA, кладём
     * `dataLayer.push` в конец body: `window.dataLayer` уже должен быть
     * инициализирован GTM (если он установлен) ДО этого скрипта, иначе наш
     * `push` создаст массив сам. Идемпотентность: `window.dataLayer || []`.
     *
     * `page_location` — полный URL текущей страницы. Браузер знает его лучше
     * сервера (`canonical` у `/city/moskva?page=2` совпадает с `/city/moskva`,
     * а человек видит обе версии), поэтому `page_location` собирается на
     * клиенте из `window.location.href`. Сервер отдаёт `page_path` (только
     * путь без query-string) и `page_type` — это всё, что нужно для GA4 без
     * расчёта полного URL.
     *
     * `page_type` — `venue|city|category|city_category|organizer|artist` —
     * берётся из LandingPage, вычислен `LandingPageService`. Для всех типов
     * страниц, кроме посадочных, в SPA есть свой `trackPageView(to.fullPath)`,
     * и эти `dataLayer.push` дополняют, а не дублируют его.
     */
    private function dataLayerSnippet(string $pageType, string $canonical): string
    {
        // `page_path` — путь без query-string. SPA берёт полный путь
        // (`to.fullPath`), посадочные страницы — относительный путь, и
        // `canonical` у них уже содержит query-string на страницах пагинации.
        // Для аналитики «по типу страницы» достаточно `page_path` — это всё,
        // что нужно для группировки в отчёте.
        $path = parse_url($canonical, PHP_URL_PATH) ?: '/';

        // Тело пушится КАК JSON-ОБЪЕКТ, а не как голая строка: если аналитика
        // не загружена, массив остаётся пустым и не выполняется ничего; если
        // загружена — тег-менеджер сам разберёт объект по правилам GA4. Ключи
        // camelCase соответствуют соглашениям GA4 (`page_location`,
        // `page_referrer`).
        return DataLayer::push([
            'event' => 'page_view',
            'page_type' => $pageType,
            'page_path' => $path,
        ]);
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

    /**
     * Список записей страницы — с разделением на «ближайшие» и «прошедшие»
     * (стратегия SEO, пункт 10, снимает B-4 частично).
     *
     * ПОЧЕМУ АРХИВ ВЫНОСИТСЯ ОТДЕЛЬНЫМ БЛОКОМ. Событие живёт два месяца, а
     * страница площадки или артиста — годами. Пока прошедшее и предстоящее
     * лежат одним списком под заголовком «Мероприятия», страница после
     * последнего концерта читается как афиша с пустой серединой: даты в прошлом
     * стоят рядом с будущими и ничем не помечены. Заголовок «Прошедшие
     * мероприятия» превращает тот же список в архив — то есть в самостоятельную
     * ценность, за которой приходят запросом «концерт … как прошёл», и ради
     * которой страница продолжает существовать в индексе. Это и есть частичное
     * снятие B-4: контент перестаёт жить ровно столько, сколько идёт продажа.
     *
     * ОДИН ЗАГОЛОВОК, КОГДА АРХИВА НЕТ. Если все записи предстоящие (обычное
     * состояние живой афиши), остаётся прежнее «Мероприятия»: называть блок
     * «Ближайшие» нечего — ближайшие и есть все, а лишний заголовок на странице
     * без архива читался бы как недоделка. Так страницы, у которых ничего не
     * изменилось, и выглядят как раньше — правка не трогает то, что работало.
     *
     * ПУСТОЙ БЛОК НЕ ВЫВОДИТСЯ. У страницы, где всё уже прошло, «Ближайшие
     * мероприятия» без единой записи — обещание, которого страница не выполняет;
     * у страницы, где всё впереди, архива нет вовсе. Оба случая закрыты выше.
     */
    private function items(LandingPage $page): string
    {
        $upcoming = $page->upcomingItems();
        $past = $page->pastItems();

        if ($past === []) {
            return '<h2>Мероприятия</h2>' . $this->eventList($upcoming);
        }

        $html = '';

        if ($upcoming !== []) {
            $html .= '<h2>Ближайшие мероприятия</h2>' . $this->eventList($upcoming);
        }

        return $html . '<h2>Прошедшие мероприятия</h2>' . $this->eventList($past, archive: true);
    }

    /**
     * Один список записей — карточки с датой, площадкой и ценой.
     *
     * @param  list<array{title: string, url: string, date: ?string, venue: ?string, price: ?string, past: bool}>  $items
     */
    private function eventList(array $items, bool $archive = false): string
    {
        $rows = '';

        foreach ($items as $item) {
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

        return sprintf('<ul class="events%s">%s</ul>', $archive ? ' events--archive' : '', $rows);
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
     * нём: `Place` на странице площадки, `Organization` на странице организатора,
     * `MusicGroup` на странице артиста или ничего. Знание о том, из каких колонок
     * собирается разметка, осталось в `StructuredDataService`; здесь только
     * вставка. Иначе шаблон страницы пришлось бы править при каждом новом типе
     * страницы — а он для того и вынесен в `strtr()`, чтобы правки текста не
     * трогали логику.
     *
     * Пустой `ItemList` не выводится: `<script>` без элементов валидатор
     * показывает как ошибку «expected a value», а не как пустой список. Крошки
     * выводятся всегда — они непусты по построению. Разметка сущности
     * пропускается по тому же признаку, что и `ItemList`. Правило «пустой блок
     * не выводится» целиком живёт в `Core\Support\JsonLd::script()` — здесь
     * только решение, какие блоки собрать.
     */
    private function jsonLd(LandingPage $page): string
    {
        $breadcrumb = json_encode(
            $this->structuredData->generateBreadcrumbList($page->breadcrumbs),
        );

        $blocks = JsonLd::script($breadcrumb);

        if ($page->items !== []) {
            $itemList = json_encode($this->structuredData->generateItemList(array_map(
                static fn (array $item): array => [
                    'name' => (string) $item['title'],
                    'url' => (string) $item['url'],
                ],
                $page->items,
            )));

            $blocks .= JsonLd::script($itemList);
        }

        if ($page->entity !== null && $page->entity !== []) {
            $blocks .= JsonLd::script(json_encode($page->entity));
        }

        return $blocks;
    }
}
