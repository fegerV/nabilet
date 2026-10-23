<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http;

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
     * и мета-тег не выводится. Эту же логику дублирует `LandingPageRenderer`,
     * чтобы посадочные страницы и страницы событий вели себя одинаково.
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

        // `geo.*` идут из площадки события: `$seo['venue']` собирается
        // `EventPageContent::venuePayload()`. Это та же площадка, что попадает
        // в JSON-LD `Place` внутри разметки события — для поисковика сигнал
        // консистентный.
        $venue = is_array($seo['venue'] ?? null) ? $seo['venue'] : [];
        $city = trim((string) ($venue['city'] ?? ''));
        $region = trim((string) ($venue['region'] ?? ''));

        if ($region !== '') {
            $tags[] = sprintf(
                '<meta name="geo.region" content="%s" />',
                htmlspecialchars($region, ENT_QUOTES),
            );
        }

        if ($city !== '') {
            $tags[] = sprintf(
                '<meta name="geo.placename" content="%s" />',
                htmlspecialchars($city, ENT_QUOTES),
            );
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
            sprintf('<meta name="description" content="%s" />', htmlspecialchars($seo['description'], ENT_QUOTES)),
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
                '{{robots}}'      => ($seo['robots'] ?? null)
                    ? sprintf('<meta name="robots" content="%s" />', htmlspecialchars((string) $seo['robots'], ENT_QUOTES))
                    : '',
                '{{image}}'       => $this->ogImageTags($seo),
                // JSON-LD НЕ htmlspecialchars-им: экранирование превратило бы
                // кавычки в `&quot;` и сделало блок невалидным JSON. При этом
                // `<`/`>` внутри строк JSON надо нейтрализовать, иначе `</script>`
                // в описании события закрывает тег и ломает страницу —
                // это классическая XSS через structured data.
                '{{jsonLd}}'      => $this->safeJsonLd($seo['jsonLd']),
                // Пустое значение дало бы `<script type="application/ld+json">`
                // без содержимого — валидатор Google показывает это как ошибку
                // «expected a value», поэтому тег не выводится вовсе, когда
                // крошек нет.
                '{{jsonLdBreadcrumb}}' => $this->breadcrumbScript($seo['jsonLdBreadcrumb'] ?? null),
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

        $payload = json_encode([
            'event' => 'page_view',
            'page_type' => 'event',
            'page_path' => $path,
            'event_id' => $eventId,
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
        $image = $seo['image'] ?? null;

        if (! is_string($image) || $image === '') {
            return '';
        }

        $tags = sprintf('<meta property="og:image" content="%s" />', htmlspecialchars($image, ENT_QUOTES));

        $alt = $seo['imageAlt'] ?? $seo['title'] ?? null;

        if (is_string($alt) && $alt !== '') {
            $tags .= sprintf('<meta property="og:image:alt" content="%s" />', htmlspecialchars($alt, ENT_QUOTES));
        }

        $width = $seo['imageWidth'] ?? null;
        $height = $seo['imageHeight'] ?? null;

        if (is_int($width) && is_int($height)) {
            $tags .= sprintf('<meta property="og:image:width" content="%d" />', $width);
            $tags .= sprintf('<meta property="og:image:height" content="%d" />', $height);
        }

        return $tags;
    }
    /**
     * `<script>` с BreadcrumbList — или пустая строка, если крошек нет.
     *
     * Вынесено из `renderSpaShell()`, потому что решает содержательный вопрос
     * (выводить тег или нет), а не вопрос подстановки: пустой `<script>` — это
     * ошибка валидации, а не «безопасный дефолт».
     */
    private function breadcrumbScript(mixed $breadcrumb): string
    {
        if (! is_string($breadcrumb) || trim($breadcrumb) === '') {
            return '';
        }

        return '<script type="application/ld+json">' . $this->safeJsonLd($breadcrumb) . '</script>';
    }
    /**
     * JSON-LD, безопасный для вставки внутрь `<script>`.
     *
     * Экранируются только `<` и `>` (в шестнадцатеричные последовательности).
     * Это валидный JSON — `\u003C` разбирается парсером как `<`, — но браузер
     * больше не увидит `</script>` внутри строкового значения и не оборвёт тег.
     * `htmlspecialchars()` здесь применять нельзя: `&quot;` сломает JSON целиком.
     */
    private function safeJsonLd(string $jsonLd): string
    {
        return str_replace(
            ['<', '>'],
            ['\u003C', '\u003E'],
            $jsonLd
        );
    }
}
