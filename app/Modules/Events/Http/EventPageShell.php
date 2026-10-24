<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http;

use Nabilet\Core\Http\SearchVerification;
use Nabilet\Core\Support\DataLayer;
use Nabilet\Core\Support\JsonLd;
use Nabilet\Core\Support\MetaTag;

/**
 * Сборка HTML-документа страницы события для поисковых роботов.
 *
 * Вынесено из `EventPageController` механическим переносом: контроллер был
 * 1480 строк и 33 методов, и половина из них собирала документ, а не
 * разбирала запрос. Здесь только «взять index.html и подставить SEO-блоки» —
 * ни одной зависимости от модели события, репозитория или сервисов, поэтому
 * класс не требует ни DI, ни БД и проверяется тестами страницы как раньше.
 *
 * ЧТО ЗДЕСЬ ВАЖНО ПОМНИТЬ
 *   `render()` подставляет блоки в собранный Vite `index.html` строковыми
 *   заменами, ограниченными первым вхождением (`limit = 1`). Это не стиль, а
 *   условие: без лимита замена `<title>` задела бы и заголовки внутри
 *   инлайн-скриптов, а `<div id="app">` — упоминания в комментариях.
 *   Подробности — в docblock самого `render()`.
 *
 * ОБЩИЕ ПРАВИЛА — В `Core`. Экранирование JSON-LD (`JsonLd`), формат
 * мета-тега и «пустое значение → нет тега» (`MetaTag`), коды верификации
 * (`SearchVerification`), обёртка `dataLayer` (`DataLayer`) раньше были
 * скопированы здесь и в двух рендерерах — посадочном и редакционном. Копии
 * разъехались молча: правка правила в одном месте не попадала в два других.
 */
final class EventPageShell
{
    /**
     * Коды верификации поисковиков и региональные `geo.*` (стратегия SEO, B-7).
     *
     * Метод возвращает ГОТОВУЮ строку для вставки в `<head>`. Пустое значение
     * означает «не вставлять ничего»: `strtr()` подставляет `''` как
     * отсутствие блока, без остаточных `<meta></meta>`.
     *
     * Коды верификации читаются из `config/nabilet.php` (`SEO_VERIFICATION_*`).
     * Сайт отдаёт их БЕЗ диагностики конфигурации: пустое значение
     * (`config('...') === null`) трактуется как «верификация ещё не пройдена»
     * и мета-тег не выводится. Раньше это правило было скопировано здесь и в
     * `LandingPageRenderer`; теперь оно одно — `Core\Http\SearchVerification`.
     *
     * `geo.region`/`geo.placename` берутся из площадки события (`venue`),
     * если она пришла в `$seo`. Для событий без площадки (онлайн-события,
     * отменённые) региональные теги не выводятся: полу-валидный `geo.*` хуже
     * его отсутствия.
     *
     * @param  array<string, mixed>  $seo
     */
    private function seoVerificationAndGeoTags(array $seo): string
    {
        // Коды верификации — владелец `Core\Http\SearchVerification`: один
        // домен, один код на всех страницах, и выводят их ещё два рендерера.
        $tags = SearchVerification::tagList();

        // `geo.*` идут из площадки события: `$seo['venue']` собирается
        // `EventPageContent::venuePayload()`. Это та же площадка, что попадает
        // в JSON-LD `Place` внутри разметки события — для поисковика сигнал
        // консистентный. Полу-валидный `geo.*` хуже отсутствия, поэтому
        // пустые значения отбрасывает `MetaTag`.
        $venue = is_array($seo['venue'] ?? null) ? $seo['venue'] : [];

        foreach ([
            'geo.region' => $venue['region'] ?? null,
            'geo.placename' => $venue['city'] ?? null,
        ] as $name => $value) {
            $tag = MetaTag::named($name, $value);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags === [] ? '' : implode('', $tags);
    }
    /**
     * Путь к собранной витрине. Отдельным методом, потому что его читают двое:
     * проверка «собрано ли» в `show()` и сам `renderSpaShell()`.
     *
     * Через `config()` с запасным значением, а не жёсткой цепочкой
     * `__DIR__ . '/../../../../../dist/index.html'`: пять уровней `..` — это
     * привязка к глубине каталога контроллера, которая ломается при любом
     * переносе файла и молча даёт «витрина не собрана». Заодно появляется
     * возможность проверить ветку 503 (P-13) в тесте, подменив путь, — иначе
     * для этого пришлось бы удалять реальную сборку.
     */
    public function path(): string
    {
        return (string) config('nabilet.storefront.shell_path', base_path('dist/index.html'));
    }
    public function render(array $seo): string
    {
        $indexFile = $this->path();
        if (! file_exists($indexFile)) {
            // Недостижимо из `show()`: он проверяет файл ДО вызова и отдаёт 503.
            // Оставлено как честный фолбэк для будущих потребителей метода —
            // раньше здесь молча возвращалась пустая HTML-оболочка с шаблонным
            // title, то есть ровно тот soft 404, который и был проблемой P-13.
            return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
                . '<title>NABILET — билеты на события</title></head>'
                . '<body><div id="app"></div></body></html>';
        }

        $html = file_get_contents($indexFile);

        // Заменяем title/description на уникальные для события.
        //
        // Ограничение `1` — не оптимизация, а условие корректности. Замена по
        // всему документу срабатывает и на упоминание тега внутри комментария, а
        // шаблон оболочки пишут руками, и строка вроде `</head>` или `<title>` в
        // пояснении к разметке — обычное дело. Тогда на странице события
        // оказывалось два `<title>` и два набора og-тегов, и какой из них возьмёт
        // конкретный парсер, зависело от парсера. У соседней замены `#app`
        // ограничение было с самого начала; здесь его недоставало.
        $html = preg_replace(
            '/<title>.*?<\/title>/s',
            sprintf('<title>%s</title>', htmlspecialchars($seo['title'], ENT_QUOTES)),
            $html,
            1
        );
        $html = preg_replace(
            '/<meta name="description"[^>]*>/',
            // `MetaTag` экранирует значение и не выведет пустой тег. Пустым
            // описание не бывает — `EventPageContent::buildDescription()`
            // заканчивается готовой формулировкой, — поэтому «нет тега» здесь
            // недостижимо и не превращается в удаление описания из оболочки.
            MetaTag::named('description', $seo['description']),
            $html,
            1
        );

        // canonical + og + JSON-LD — вставляем перед </head>.
        // base href="/": многие страницы живут по пути вроде /event/:slug/seats —
        // без неё относительные ./assets из dist/index.html резолвятся неверно.
        //
        // Плейсхолдеры вместо sprintf: строка со сборкой из восьми позиционных
        // `%s` уже один раз поехала (аргумент `og:image` мог стать `null` и
        // сдвинуть все последующие), а порядок здесь критичен. `strtr` по
        // именам ключей такую ошибку делает невозможной.
        //
        // `{{headExtras}}` (стратегия SEO, B-7) — необязательные мета-теги
        // верификации Яндекс/Google и региональные `geo.*`. Теги собираются
        // строкой-методом и подставляются в шаблон; пустое значение означает
        // «ничего не вставлять», чтобы не плодить пустые `<meta>` в HTML.
        $seoBlock = '<base href="/" />' . strtr(
            '<link rel="canonical" href="{{canonical}}" />'
            . '<meta property="og:type" content="website" />'
            // `og:locale` отсутствовал (аудит P-18): соцсеть не могла выбрать
            // язык превью и подставляла свой. `ru_RU` соответствует `lang="ru"`
            // в оболочке; при появлении второго языка (аудит P-11) значение
            // станет производным от локали страницы, а пока их две версии нет.
            . '<meta property="og:locale" content="ru_RU" />'
            . '<meta property="og:title" content="{{title}}" />'
            . '<meta property="og:description" content="{{description}}" />'
            . '<meta property="og:url" content="{{canonical}}" />'
            . '{{robots}}'
            . '{{image}}'
            . '{{headExtras}}'
            . '<script type="application/ld+json">{{jsonLd}}</script>'
            . '{{jsonLdBreadcrumb}}',
            [
                '{{canonical}}'   => htmlspecialchars($seo['canonical'], ENT_QUOTES),
                '{{title}}'       => htmlspecialchars($seo['title'], ENT_QUOTES),
                '{{description}}' => htmlspecialchars($seo['description'], ENT_QUOTES),
                '{{robots}}'      => MetaTag::named('robots', $seo['robots'] ?? null),
                '{{image}}'       => $this->ogImageTags($seo),
                // JSON-LD НЕ htmlspecialchars-им: экранирование превратило бы
                // кавычки в `&quot;` и сделало блок невалидным JSON. При этом
                // `<`/`>` внутри строк JSON надо нейтрализовать, иначе `</script>`
                // в описании события закрывает тег и ломает страницу —
                // это классическая XSS через structured data. Правило живёт в
                // `Core\Support\JsonLd::escape()`.
                '{{jsonLd}}'      => JsonLd::escape((string) ($seo['jsonLd'] ?? '')),
                // Пустое значение дало бы `<script type="application/ld+json">`
                // без содержимого — валидатор Google показывает это как ошибку
                // «expected a value», поэтому тег не выводится вовсе, когда
                // крошек нет.
                '{{jsonLdBreadcrumb}}' => JsonLd::script($seo['jsonLdBreadcrumb'] ?? null),
                // B-7: см. `seoVerificationAndGeoTags()` ниже.
                '{{headExtras}}'  => $this->seoVerificationAndGeoTags($seo),
            ]
        );

        // Та же причина, что у двух замен выше: закрывающий тег головной секции
        // обязан быть найден в разметке, а не в комментарии к ней. `str_replace`
        // без счётчика вставлял блок столько раз, сколько раз строка встречается
        // в документе, — то есть дублировал canonical, og и JSON-LD.
        //
        // Через `preg_replace_callback`, а не `preg_replace`: в строке замены
        // последний трактует `$1` и `\1` как ссылки на группы, а `$seoBlock`
        // содержит заголовок и описание события — пользовательский текст, в
        // котором `$` и `\` встретиться могут. Колбэк возвращает строку как есть.
        $html = preg_replace_callback(
            '/<\/head>/',
            static fn (): string => $seoBlock . '</head>',
            $html,
            1
        ) ?? $html;

        // Видимый серверный блок статуса (аудит P-05/P-17). Vue при монтировании
        // очистит контейнер, поэтому пользователь его не увидит — блок существует
        // ровно для краулеров, которые JavaScript не исполняют.
        if (($seo['bodyFallback'] ?? '') !== '') {
            $html = preg_replace(
                '/<div id="app">\s*<\/div>/',
                '<div id="app">' . $seo['bodyFallback'] . '</div>',
                $html,
                1
            ) ?? $html;
        }

        // `dataLayer.push` для GTM/GA4/Яндекс.ТэгМенеджер (стратегия SEO, B-7).
        //
        // SPA уже вызывает `trackPageView(to.fullPath)` на каждом переходе
        // маршрутизатора, но это происходит ТОЛЬКО после монтирования Vue. До
        // него (и в сценарии «SPA не собрана → 503», и в сценарии «JS
        // заблокирован → серверный блок читается») события `page_view` с
        // `page_type: 'event'` не уходят. Серверный пуш закрывает оба
        // случая: пустой массив `window.dataLayer || []` безвреден, если
        // GTM не загружен, и не дублирует событие, если загружен — GTM
        // обрабатывает оба `push` как два просмотра, а не схлопывает их.
        $html = preg_replace_callback(
            '/<\/body>/',
            static fn (): string => self::dataLayerEventPageSnippet($seo) . '</body>',
            $html,
            1
        ) ?? $html;

        return $html;
    }
    /**
     * Серверный `dataLayer.push` для страницы события (стратегия SEO, B-7).
     *
     * `event_id` берётся из `public_id` — это и есть идентификатор события в
     * отчётах. `page_type` фиксирован: «event». `page_path` — относительный
     * путь без query-string, чтобы пагинация не считалась отдельной
     * страницей в аналитике.
     *
     * Метод `static`, потому что не зависит от состояния контроллера и
     * вызывается из колбэка `preg_replace_callback`. Тело пушится
     * JSON-объектом: `page_type` будет читаться GA4 как пользовательское
     * измерение, если в GTM настроено соответствующее определение custom
     * dimension.
     *
     * @param  array<string, mixed>  $seo
     */
    private static function dataLayerEventPageSnippet(array $seo): string
    {
        $path = parse_url((string) ($seo['canonical'] ?? ''), PHP_URL_PATH) ?: '/event';
        $eventId = (string) ($seo['eventPublicId'] ?? '');

        return DataLayer::push([
            'event' => 'page_view',
            'page_type' => 'event',
            'page_path' => $path,
            'event_id' => $eventId,
        ]);
    }
    /**
     * Теги `og:image`, включая alt и размеры (аудит P-18).
     *
     * `og:image:alt` обязателен для доступности превью: без него парсеры
     * показывают карточку без описания картинки, а скринридеры — без
     * альтернативного текста.
     *
     * Размеры выводятся ТОЛЬКО когда они известны. У постера они не читаются с
     * диска намеренно: неверное значение хуже отсутствующего — парсер обрежет
     * превью именно по нему, тогда как без размера он определит их сам после
     * загрузки.
     *
     * @param  array<string, mixed>  $seo
     */
    private function ogImageTags(array $seo): string
    {
        // Первый тег — заодно проверка «картинка есть»: без `og:image`
        // остальные три не имеют смысла.
        $tags = MetaTag::property('og:image', $seo['image'] ?? null);

        if ($tags === '') {
            return '';
        }

        // `og:image:alt` обязателен для доступности превью: без него парсеры
        // показывают карточку без описания картинки, а скринридеры — без
        // альтернативного текста. Пустое значение не подменяется заголовком —
        // это поведение сохранено: `??` не пропускает пустую строку.
        $tags .= MetaTag::property('og:image:alt', $seo['imageAlt'] ?? $seo['title'] ?? null);

        // Размеры выводятся ТОЛЬКО когда известны оба. У постера они не
        // читаются с диска намеренно: неверное значение хуже отсутствующего —
        // парсер обрежет превью именно по нему, тогда как без размера он
        // определит их сам после загрузки.
        $width = $seo['imageWidth'] ?? null;
        $height = $seo['imageHeight'] ?? null;

        if (is_int($width) && is_int($height)) {
            $tags .= MetaTag::property('og:image:width', $width);
            $tags .= MetaTag::property('og:image:height', $height);
        }

        return $tags;
    }
}
