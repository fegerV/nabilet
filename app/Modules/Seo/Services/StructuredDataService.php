<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Nabilet\Core\Support\AssetUrl;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;

/**
 * Structured data (JSON-LD) generator for events (ТЗ §38).
 *
 * Generates schema.org Event markup with nested Offer, Place, and Organization
 * entities. This enables rich snippets in search results.
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ (аудит P-09)
 *
 * `performer` и `eventAttendanceMode` выводятся ТОЛЬКО при наличии данных.
 * Соблазн «заполнить для полноты карточки» здесь опаснее пустоты:
 *
 *   — `performer` — это артист. В `events` нет колонки с ним (см.
 *     `nabilet_core_spec/migrations.sql:132`), а подставить туда организацию
 *     нельзя: `organizer` уже занят ею же, и Google увидит событие, у которого
 *     устроитель и исполнитель — одно юрлицо. Разметка станет противоречивой.
 *     Поле опционально, его отсутствие ошибкой не является.
 *   — `eventAttendanceMode` по умолчанию `OfflineEventAttendanceMode` — и это
 *     ВЕРНЫЙ дефолт для продукта с выбором места на схеме зала: онлайн-событий
 *     у платформы нет. Колонки `attendance_mode` в таблице тоже нет.
 *
 * `ItemList` (аудит P-09) появился вместе с посадочными страницами (аудит P-12)
 * — до них у него не было носителя: каталог это hash-SPA с JSON-API, у него нет
 * серверного URL и серверного HTML, куда разметку можно вставить. Теперь носитель
 * есть (`LandingPageRenderer`), и `generateBreadcrumbList()` даёт верхние уровни,
 * а `generateItemList()` — сам список.
 *
 * `generatePlace()` и `generateOrganization()` (аудит P-12) — та же причина.
 * Блоки `location` и `organizer` внутри `generateEventData()` описывают место и
 * устроителя ИЗ КОНТЕКСТА СОБЫТИЯ: там достаточно имени. Страницы площадки и
 * организатора — самостоятельные документы со своими адресами, поэтому им нужны
 * блоки, описывающие саму сущность, а не ссылку на неё.
 */
class StructuredDataService
{
    /**
     * Mapping of internal event statuses to schema.org EventStatus types.
     */
    private const EVENT_STATUS_MAP = [
        EventStateMachine::PUBLISHED => 'https://schema.org/EventScheduled',
        EventStateMachine::SCHEDULED => 'https://schema.org/EventScheduled',
        EventStateMachine::CANCELLED => 'https://schema.org/EventCancelled',
        EventStateMachine::COMPLETED => 'https://schema.org/EventCompleted',
        EventStateMachine::ARCHIVED => 'https://schema.org/EventCompleted',
    ];

    /**
     * Default event status when status is unknown or draft.
     */
    private const DEFAULT_EVENT_STATUS = 'https://schema.org/EventScheduled';

    /**
     * Attendance mode mappings.
     */
    private const ATTENDANCE_MODE_MAP = [
        'offline' => 'https://schema.org/OfflineEventAttendanceMode',
        'online' => 'https://schema.org/OnlineEventAttendanceMode',
        'mixed' => 'https://schema.org/MixedEventAttendanceMode',
    ];

    /**
     * Default attendance mode.
     */
    private const DEFAULT_ATTENDANCE_MODE = 'https://schema.org/OfflineEventAttendanceMode';

    /**
     * Generate JSON-LD structured data for an event page.
     *
     * @param array<string, mixed> $eventData Event data including relations
     *
     * @return array<string, mixed> JSON-LD structure
     */
    public function generateEventData(array $eventData): array
    {
        $baseUrl = config('app.url');

        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $eventData['title'] ?? '',
            'description' => $eventData['short_description'] ?? $eventData['description'] ?? '',
            'image' => $this->buildImageUrls($eventData),
            'startDate' => $this->formatDateTime($eventData['start_datetime'] ?? null),
            'endDate' => $this->formatDateTime($eventData['end_datetime'] ?? null),
            'eventStatus' => $this->getEventStatus($eventData['status'] ?? EventStateMachine::DRAFT),
            'eventAttendanceMode' => $this->getAttendanceMode($eventData['attendance_mode'] ?? 'offline'),
            'url' => $this->getCanonicalUrl($eventData),
            'location' => [
                '@type' => 'Place',
                'name' => $eventData['venue']['name'] ?? '',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $eventData['venue']['address'] ?? '',
                    'addressLocality' => $eventData['venue']['city'] ?? '',
                    'postalCode' => $eventData['venue']['postal_code'] ?? '',
                    'addressCountry' => $eventData['venue']['country'] ?? 'RU',
                ],
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $eventData['organization']['name'] ?? '',
                'url' => $baseUrl,
            ],
            'offers' => $this->buildOffers($eventData),
        ];

        // Add performer if applicable
        if (! empty($eventData['performer'])) {
            $structuredData['performer'] = [
                '@type' => 'PerformingGroup',
                'name' => $eventData['performer']['name'] ?? '',
            ];
        }

        // Убираем пустые значения ПОСЛЕ сборки, а не проверками на каждом поле.
        //
        // ПОЧЕМУ ЭТО НУЖНО. Схема ниже читает поля через `?? ''`, поэтому
        // отсутствующая в исходных данных площадка даёт `"name": ""`, а
        // отсутствующий `postal_code` — `"postalCode": ""`. Валидатор Google
        // считает пустую строку значением и ругается на неполное поле:
        // `location.address` с пустым `streetAddress` — это ошибка, тогда как
        // ОТСУТСТВИЕ ключа ошибкой не является (поле просто опционально).
        //
        // Спецификация JSON-LD на этот счёт однозначна: незнакомые ключи мало
        // того что игнорируются — они и не ожидаются. `"validFrom": null`
        // уезжало наружу ровно так же (см. `buildOffers()`), и это тоже
        // отбраковывалось.
        //
        // `array_filter` с такой лямбдой, а не без неё: без лямбды PHP вырежет
        // и `0`, и `'0'`, а `"price": 0` для бесплатного мероприятия —
        // осмысленное значение, которое терять нельзя.
        return $this->pruneEmpty($structuredData);
    }

    /**
     * JSON-LD BreadcrumbList (аудит P-09).
     *
     * ЗАЧЕМ. Хлебные крошки — единственный способ сообщить поисковику, что
     * страница мероприятия лежит ВНУТРИ сайта, а не является его корнем.
     * Google подставляет их в сниппет вместо голого URL: в выдаче видно
     * «nabilet.ru › События › Новогодний концерт» вместо
     * «nabilet.ru/event/novyj-god/01H…». Плюс это убирает сам URL из
     * сниппета, а вместе с ним и мусорный сегмент `public_id`.
     *
     * ПОЧЕМУ СПИСОК, А НЕ ЗАХАРДКОЖЕННАЯ ЦЕПОЧКА. Глубина крошек зависит от
     * того, какие посадочные страницы РЕАЛЬНО существуют (аудит P-12: страниц
     * города, категории и площадки пока нет). Метод принимает любое число
     * уровней, поэтому добавление уровня категории — это одна строка в
     * вызывающем коде, а не переписывание генератора.
     *
     * ПУСТОЕ `name` ПРОПУСКАЕТСЯ, `position` ПЕРЕНУМЕРОВЫВАЕТСЯ. Google
     * требует, чтобы `position` шёл подряд от 1 без дыр; при фильтрации
     * пустых имён счётчик по индексу массива дал бы разрыв и разметка была бы
     * отброшена. Поэтому позиция берётся от числа УЖЕ добавленных элементов.
     *
     * `item` (URL уровня) необязателен по спецификации, но Google обходит
     * эти ссылки и отмечает битые как «URL not found» в Search Console.
     * Поэтому вызывающий код передаёт `url` только для адресов, которые
     * действительно отвечают 200, — а последний уровень (текущая страница)
     * получает канонический URL, чтобы проверка «последняя позиция ==
     * canonical» проходила.
     *
     * @param  list<array{name: string, url?: string|null}>  $trail  уровни от корня к текущей странице
     * @return array<string, mixed>
     */
    public function generateBreadcrumbList(array $trail): array
    {
        $items = [];

        foreach ($trail as $crumb) {
            $name = trim((string) ($crumb['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $item = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => $name,
            ];

            $url = trim((string) ($crumb['url'] ?? ''));

            if ($url !== '') {
                $item['item'] = $url;
            }

            $items[] = $item;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * JSON-LD ItemList для посадочных страниц (аудит P-12).
     *
     * ЗАЧЕМ. Список ссылок на странице города — это и есть её содержимое.
     * `ItemList` сообщает поисковику, что перед ним именно упорядоченный перечень
     * однотипных объектов, а не набор случайных ссылок: Google использует его для
     * карусели в выдаче и, что важнее, для понимания, что страница — хаб раздела,
     * а не отдельная карточка.
     *
     * ПОЧЕМУ `url`, А НЕ ВЛОЖЕННЫЙ `Event`. Полная разметка каждого события
     * внутри списка дублировала бы то, что уже отдаёт страница события, и
     * расходилась бы с ней при первой же правке (цена, дата, статус). `ListItem`
     * с `url` — минимальная форма, которая не может противоречить странице,
     * потому что не утверждает о событии ничего, кроме его адреса.
     *
     * ПОЗИЦИЯ СЧИТАЕТСЯ ОТ ЧИСЛА ДОБАВЛЕННЫХ, а не от индекса массива, — по той
     * же причине, что в `generateBreadcrumbList()`: элемент без URL пропускается,
     * и счётчик по индексу оставил бы дыру в нумерации («1, 3»), из-за которой
     * Google отбрасывает список целиком.
     *
     * @param  list<array{name?: string|null, url?: string|null}>  $items
     * @return array<string, mixed>
     */
    public function generateItemList(array $items): array
    {
        $elements = [];

        foreach ($items as $item) {
            $url = trim((string) ($item['url'] ?? ''));

            // Без адреса элемент бессмыслен: `ListItem` без `item`/`url` не
            // указывает ни на что, и валидатор Google считает его ошибкой.
            if ($url === '') {
                continue;
            }

            $element = [
                '@type' => 'ListItem',
                'position' => count($elements) + 1,
                'url' => $url,
            ];

            $name = trim((string) ($item['name'] ?? ''));

            if ($name !== '') {
                $element['name'] = $name;
            }

            $elements[] = $element;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * JSON-LD Place для страницы площадки (аудит P-12, B-7).
     *
     * ЗАЧЕМ ОТДЕЛЬНЫЙ БЛОК, ЕСЛИ `Place` УЖЕ ЕСТЬ В РАЗМЕТКЕ СОБЫТИЯ. Там он
     * описывает место, где проходит КОНКРЕТНОЕ событие, и существует в контексте
     * этого события. Страница площадки — самостоятельный документ: у неё свой
     * адрес, свой canonical и свой список мероприятий. `Place` на ней сообщает
     * поисковику, что предмет страницы — именно это место, а не подборка.
     *
     * ПОЧЕМУ `pruneEmpty`, А НЕ ПОДСТАНОВКИ ПО УМОЛЧАНИЮ. `venues.address`,
     * `venues.city` и `venues.country` объявлены nullable: администратор заводит
     * площадку раньше, чем узнаёт адрес. Пустая строка в `streetAddress` для
     * валидатора Google — ошибка неполного поля, тогда как ОТСУТСТВИЕ ключа
     * ошибкой не является: поле просто опционально.
     *
     * `postalCode` здесь СОЗНАТЕЛЬНО НЕ ВЫВОДИТСЯ: такой колонки в `venues` нет
     * (`2026_09_20_000300_003_venues_schemas.php`), и подставить туда что-либо
     * значило бы выдумать данные. В разметке события это поле читается как
     * `?? ''` и вычищается тем же `pruneEmpty` — то есть сегодня не выводится и
     * там. Строка осталась в `generateEventData()` как совместимость с входным
     * массивом, который собирает контроллер, а не как источник истины.
     *
     * `addressCountry` — ЕДИНСТВЕННОЕ ПОЛЕ С ЗНАЧЕНИЕМ ПО УМОЛЧАНИЮ, и это не
     * непоследовательность. `venues.country` nullable, и без дефолта площадка без
     * адреса дала бы `"address": {"@type": "PostalAddress"}` — адрес, у которого
     * нет ни одного поля. `pruneEmpty` такой объект НЕ убирает: он непуст, в нём
     * есть `@type`. Дефолт `RU` совпадает с тем, что уже отдаёт
     * `generateEventData()` для `location.address`, и это существеннее
     * формальной чистоты: две разметки одного и того же места на двух страницах
     * одного сайта не должны расходиться в стране.
     *
     * `addressRegion` (стратегия SEO, B-7) выводится ТОЛЬКО при наличии
     * `venues.region`. Яндекс.Вебмастер использует регион для геозависимой
     * выдачи: запрос «концерты в Москве» в геозависимом режиме опирается на
     * структурированный адрес, а не на свободный текст. `schema.org` поле
     * опционально, отсутствие ключа — не ошибка, поэтому `null`-регион просто
     * отбрасывается `pruneEmpty`-ом.
     *
     * `areaServed` (B-7) — `AdministrativeArea` с именем города. Это явная
     * декларация зоны обслуживания: «страница описывает место, которое работает
     * для города N». Яндекс и Google читают поле как подтверждение привязки к
     * региону, и для локальной выдачи это сильнее, чем `addressLocality` внутри
     * `PostalAddress`. Если города нет — поле не выводится, потому что
     * «обслуживаем неизвестно где» хуже, чем его отсутствие.
     *
     * @param  array{name?: ?string, url?: ?string, address?: ?string, city?: ?string, region?: ?string, country?: ?string}  $place
     * @return array<string, mixed>
     */
    public function generatePlace(array $place): array
    {
        $city = trim((string) ($place['city'] ?? ''));
        $areaServed = $city === '' ? null : [
            '@type' => 'AdministrativeArea',
            'name' => $city,
        ];

        return $this->pruneEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Place',
            'name' => trim((string) ($place['name'] ?? '')),
            'url' => trim((string) ($place['url'] ?? '')),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => trim((string) ($place['address'] ?? '')),
                'addressLocality' => $city,
                'addressRegion' => trim((string) ($place['region'] ?? '')),
                'addressCountry' => trim((string) ($place['country'] ?? '')) ?: 'RU',
            ],
            'areaServed' => $areaServed,
        ]);
    }

    /**
     * JSON-LD Organization для страницы организатора (аудит P-12).
     *
     * ЧЕМ ОТЛИЧАЕТСЯ ОТ БЛОКА `organizer` В РАЗМЕТКЕ СОБЫТИЯ. Тот — ссылка на
     * устроителя из контекста события: имени и адреса сайта достаточно, потому
     * что предмет страницы — событие. Здесь организация сама предмет, поэтому
     * выводятся все поля, которые у неё реально есть: описание, логотип,
     * контакты.
     *
     * НИ ОДНО ПОЛЕ НЕ ПОДСТАВЛЯЕТСЯ ПО УМОЛЧАНИЮ. Пустой `email` — это
     * утверждение «почта есть, но пустая», а не «почты нет»; `pruneEmpty`
     * убирает ключ целиком, и это верная форма. `url` приходит от вызывающего
     * кода — он знает и адрес страницы организатора, и `APP_URL`.
     *
     * `logo` проходит через `AssetUrl`, а не склеивается здесь: в проекте два
     * разных корня (`/storage/...` для загруженных файлов и `/` для статики
     * приложения), и ручная склейка уже однажды дала `og:image` со ссылкой в 404
     * — см. докблок `AssetUrl`.
     *
     * @param  array{name?: ?string, url?: ?string, description?: ?string, logo?: ?string, email?: ?string, phone?: ?string}  $organization
     * @return array<string, mixed>
     */
    public function generateOrganization(array $organization): array
    {
        return $this->pruneEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => trim((string) ($organization['name'] ?? '')),
            'url' => trim((string) ($organization['url'] ?? '')),
            'description' => trim((string) ($organization['description'] ?? '')),
            'logo' => AssetUrl::storageOrNull($organization['logo'] ?? null),
            'email' => trim((string) ($organization['email'] ?? '')),
            'phone' => trim((string) ($organization['phone'] ?? '')),
        ]);
    }

    /**
     * JSON-LD MusicGroup для страницы артиста (стратегия SEO, B-1).
     *
     * ПОЧЕМУ `MusicGroup`, А НЕ `Person`. Артист в каталоге бывает и группой
     * («Би-2»), и солистом, и в схеме нет колонки, которая бы это различала:
     * `artists` описывает КАТАЛОЖНУЮ сущность с адресом и списком событий, а не
     * физическое лицо. `MusicGroup` — это подтип `PerformingGroup`, и Google
     * принимает его для исполнителя в разметке события (`performer`). Если
     * когда-нибудь появится признак «солист», тип поменяется здесь одной
     * строкой; выдумывать его сегодня из имени нельзя — «Анна Нетребко» и
     * «Ночные снайперы» различаются не по строке, а по данным, которых нет.
     *
     * ЧЕМ ОТЛИЧАЕТСЯ ОТ БЛОКА `performer` В РАЗМЕТКЕ СОБЫТИЯ. Тот — ссылка на
     * исполнителя ИЗ КОНТЕКСТА события: имени достаточно. Здесь артист сам
     * предмет страницы, поэтому выводятся все поля, которые у него реально есть:
     * жанр, биография, фотография, ссылки на соцсети.
     *
     * `genre` ВЫВОДИТСЯ СТРОКОЙ, А НЕ СПИСКОМ. В схеме это `VARCHAR(255)`, то
     * есть одно значение, а не набор. `MusicGroup.genre` по спецификации
     * принимает и строку, и массив; строка здесь — точная форма того, что
     * лежит в колонке, а не придуманное дробление по запятой (жанр вида
     * «рок, поп» — это одна строка администратора, а не два жанра).
     *
     * `sameAs` — ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ПРИХОДИТ `social_links`. Google
     * использует `sameAs` для подтверждения, что сущности на разных сайтах —
     * это одна и та же сущность, поэтому сюда попадают только САМИ АДРЕСА, без
     * подписей: `{"vk": "https://vk.com/..."}` и `["https://vk.com/..."]`
     * дают один и тот же список URL. Значение с подписью (`{label, url}`)
     * тоже разбирается — на случай, если карточку артиста заполняли руками.
     *
     * `photo` проходит через `AssetUrl`, как и `logo` у организации: в проекте
     * два разных корня, и ручная склейка уже однажды дала `og:image` со ссылкой
     * в 404 (см. докблок `AssetUrl`).
     *
     * НИ ОДНО ПОЛЕ НЕ ПОДСТАВЛЯЕТСЯ ПО УМОЛЧАНИЮ, и `pruneEmpty` здесь по той
     * же причине, что у `generateOrganization()`: пустой `bio` — это
     * утверждение «биография есть, но пустая», а не «биографии нет». Валидатор
     * Google считает пустую строку значением и ругается на неполное поле, тогда
     * как отсутствие ключа ошибкой не является — поле просто опционально.
     *
     * @param  array{name?: ?string, url?: ?string, bio?: ?string, photo?: ?string, genre?: ?string, social_links?: mixed}  $artist
     * @return array<string, mixed>
     */
    public function generateArtist(array $artist): array
    {
        return $this->pruneEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'MusicGroup',
            'name' => trim((string) ($artist['name'] ?? '')),
            'url' => trim((string) ($artist['url'] ?? '')),
            'description' => trim((string) ($artist['bio'] ?? '')),
            'image' => AssetUrl::storageOrNull($artist['photo'] ?? null),
            'genre' => trim((string) ($artist['genre'] ?? '')),
            'sameAs' => $this->socialUrls($artist['social_links'] ?? null),
        ]);
    }

    /**
     * JSON-LD Article для редакционной страницы CMS (стратегия SEO, B-2).
     *
     * ЧЕМ ОТЛИЧАЕТСЯ ОТ `Event`. Событие — это краткосрочный объект с
     * `startDate` и местом проведения, редакционная страница — это ДОКУМЕНТ с
     * автором и/или издателем, у которого есть дата публикации и правки.
     * Обе схемы используются на сайте, и `Article` для статей — это то, что
     * Google показывает в расширенных результатах под запросы «как вернуть
     * билет», «правила посещения», «о компании».
     *
     * `author` И `publisher` НЕ ОБЯЗАТЕЛЬНЫ ПО СПЕЦИФИКАЦИИ — они условные
     * поля, и `pruneEmpty()` убирает их вместе с подполями, если имени нет.
     * Для редакционной страницы организации (`organizationName` присутствует)
     * они заполняются; для общей страницы (`organizationName = null`) — нет.
     * Не выдумывать издателя «по умолчанию» — это противоречие между разметкой
     * и реальностью, и валидатор Google его ловит.
     *
     * `datePublished` И `dateModified` ВЫВОДЯТСЯ СТРОГО В ИЗО8601. У Content
     * `pages` нет отдельной колонки «дата публикации черновика» — это и есть
     * `published_at`, и страница без неё не публикуется (`PageService::forSlug()`).
     * `dateModified` — `updated_at` модели, и она меняется при любой правке.
     * Строка даты в `<script>` обязана быть валидным ISO, иначе валидатор
     * Google считает поле ошибкой типа «expected ISO 8601 date».
     *
     * `mainEntityOfPage` — КАНОНИЧЕСКИЙ АДРЕС. Страница одна, других
     * представлений у неё нет (нет пагинации, нет локалей), и именно
     * canonical сообщает поисковику «это основной адрес документа».
     *
     * `image` НЕ ВЫВОДИТСЯ. У редакционных страниц пока нет поля обложки
     * (`pages` — `public_id`/`organization_id`/`title`/`slug`/`content`/
     * `status`/`published_at`), и `og:image` тоже не выводится в рендере.
     * Выдумывать дефолтную картинку здесь нельзя: для `Event` дефолт
     * осмыслен (карточка события без картинки — потеря в расширенном
     * результате), для статьи с заголовком без обложки это просто статья без
     * картинки, и поле `image` опционально.
     *
     * @param  array{headline?: ?string, description?: ?string, url?: ?string, datePublished?: ?string, dateModified?: ?string, publisherName?: ?string, authorName?: ?string}  $article
     * @return array<string, mixed>
     */
    public function generateArticle(array $article): array
    {
        $publisherName = trim((string) ($article['publisherName'] ?? ''));
        $authorName = trim((string) ($article['authorName'] ?? $publisherName));

        return $this->pruneEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => trim((string) ($article['headline'] ?? '')),
            'description' => trim((string) ($article['description'] ?? '')),
            'url' => trim((string) ($article['url'] ?? '')),
            'mainEntityOfPage' => trim((string) ($article['url'] ?? '')),
            'datePublished' => trim((string) ($article['datePublished'] ?? '')),
            'dateModified' => trim((string) ($article['dateModified'] ?? '')),
            // Издатель и автор — ОДНА ОРГАНИЗАЦИЯ для v1: редакционных
            // учёток автора в схеме `users` нет, а админ пишет от лица
            // площадки. Когда появится персональный автор — здесь одна
            // строка правки: `'author' => $this->pruneEmpty([...])`.
            'author' => $authorName === '' ? null : [
                '@type' => 'Organization',
                'name' => $authorName,
            ],
            'publisher' => $publisherName === '' ? null : [
                '@type' => 'Organization',
                'name' => $publisherName,
            ],
        ]);
    }

    /**
     * Список адресов из `social_links` — в любом из двух встречающихся видов.
     *
     * Форма колонки не зафиксирована: `social_links` — `JSON NULL`, и заполняет
     * её либо импорт (списком адресов), либо рука администратора (картой
     * «сеть → адрес»). Обе формы означают одно и то же, и обе обязаны дать
     * непустой `sameAs`; иначе разметка молча теряла бы ссылки на соцсети в
     * зависимости от того, как их ввели.
     *
     * Пустые значения отбрасываются здесь, а не в `pruneEmpty`: тот убрал бы
     * пустой элемент из массива, но НЕ убрал бы сам ключ, если после чистки
     * массив остался непустым, — а нам нужен именно список URL.
     *
     * @return list<string>
     */
    private function socialUrls(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $urls = [];

        foreach ($links as $value) {
            // Карта «сеть → адрес» и просто список адресов различаются только
            // типом значения: строка — это адрес, массив — запись с подписью.
            $candidate = is_array($value)
                ? ($value['url'] ?? null)
                : $value;

            if (is_string($candidate) && trim($candidate) !== '') {
                $urls[] = trim($candidate);
            }
        }

        return $urls;
    }

    /**
     * Рекурсивно вычищает пустые строки, null и пустые массивы/объекты.
     *
     * `false` и `0` сохраняются: в schema.org это валидные значения
     * (`isAccessibleForFree: false`, `price: 0`).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function pruneEmpty(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->pruneEmpty($value);

                if ($value === []) {
                    unset($data[$key]);
                    continue;
                }

                $data[$key] = $value;
                continue;
            }

            if ($value === null || $value === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Get canonical URL for an event.
     *
     * @param array<string, mixed> $eventData
     */
    public function getCanonicalUrl(array $eventData): string
    {
        // Use explicit canonical_url if set, otherwise generate from slug/public_id
        if (!empty($eventData['canonical_url'])) {
            return $eventData['canonical_url'];
        }

        return route('events.show', [
            'slug' => $eventData['slug'] ?? '',
            'publicId' => $eventData['public_id'] ?? '',
        ]);
    }

    /**
     * Map internal status to schema.org EventStatus.
     */
    private function getEventStatus(string $status): string
    {
        return self::EVENT_STATUS_MAP[$status] ?? self::DEFAULT_EVENT_STATUS;
    }

    /**
     * Get attendance mode from internal representation.
     */
    private function getAttendanceMode(string $mode): string
    {
        return self::ATTENDANCE_MODE_MAP[$mode] ?? self::DEFAULT_ATTENDANCE_MODE;
    }

    /**
     * Build image URLs array for structured data.
     *
     * @param array<string, mixed> $eventData Event data
     *
     * @return array<int, string> Image URLs
     */
    private function buildImageUrls(array $eventData): array
    {
        $images = [];

        if (! empty($eventData['poster'])) {
            $images[] = $this->resolveImageUrl($eventData['poster']);
        }

        if (! empty($eventData['cover'])) {
            $images[] = $this->resolveImageUrl($eventData['cover']);
        }

        // Заглушка, когда у события нет ни постера, ни обложки.
        //
        // `image` — ОБЯЗАТЕЛЬНОЕ поле для schema.org/Event: без него Google
        // не покажет событие в расширенном результате, даже если всё остальное
        // заполнено. Поэтому «нет картинки» здесь нельзя выразить отсутствием
        // ключа — нужен рабочий адрес.
        //
        // Файл раньше не существовал (`.jpg`), и ссылка вела в 404, что для
        // валидатора хуже отсутствия: он видит поле и проверяет его.
        // Лежит в `public/images/`, а не в `storage/`, потому что это статика
        // приложения, а не загруженный пользователем файл.
        //
        // РАСТРОВЫЙ PNG, А НЕ SVG (аудит P-18). SVG-версию не читают парсеры VK
        // и iMessage: в разметке она проходила, а карточка ссылки в мессенджере
        // раскрывалась без картинки. Файл собирается `tools/gen-og-default.php`.
        return $images !== [] ? $images : [config('app.url') . '/images/og-default.png'];
    }

    /**
     * Resolve image URL from storage path.
     */
    private function resolveImageUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return config('app.url') . '/storage/' . ltrim($path, '/');
    }

    /**
     * Build offers array for structured data.
     *
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    private function buildOffers(array $eventData): array
    {
        // Handle multiple tickets if provided
        if (! empty($eventData['tickets']) && is_array($eventData['tickets'])) {
            $offers = [];
            foreach ($eventData['tickets'] as $ticket) {
                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $ticket['name'] ?? 'General Admission',
                    'price' => $ticket['price'] ?? 0,
                    'priceCurrency' => $ticket['currency'] ?? 'RUB',
                    'availability' => $this->getOfferAvailability($ticket['status'] ?? 'available'),
                    'validFrom' => $this->formatDateTime($ticket['sale_start'] ?? null),
                    'validThrough' => $this->formatDateTime($ticket['sale_end'] ?? $eventData['start_datetime'] ?? null),
                    // `route('checkout.select', …)` здесь стоял раньше — и такого
                    // маршрута нет ни в одном `routes/*`. Пока ветка не вызывалась,
                    // это была мёртвая строка; при первом же вызове она уронила бы
                    // SEO-страницу `RouteNotFoundException` (500). Ведём на саму
                    // страницу события: она и есть точка входа в покупку.
                    'url' => $this->getCanonicalUrl($eventData),
                ];
            }
            return $offers;
        }

        // Single default offer
                return [
                    '@type' => 'Offer',
                    'name' => 'General Admission',
                    'url' => $this->getCanonicalUrl($eventData),
                    'price' => $eventData['ticket_price'] ?? 0,
                    'priceCurrency' => $eventData['currency'] ?? 'RUB',
                    // `availability` (из инвентаря) важнее статуса события: у
                    // опубликованного события с распроданным залом статус даёт
                    // `InStock`, и разметка обещает билеты, которых нет.
                    'availability' => $this->getOfferAvailability(
                        $eventData['availability'] ?? $eventData['status'] ?? EventStateMachine::DRAFT
                    ),
                    'validFrom' => $this->formatDateTime($eventData['published_at'] ?? null),
                ];
    }

    /**
     * Map event/ticket status to schema.org ItemAvailability.
     */
    private function getOfferAvailability(string $status): string
    {
        return match ($status) {
            'sold_out', EventStateMachine::COMPLETED, EventStateMachine::ARCHIVED => 'https://schema.org/SoldOut',
            'limited' => 'https://schema.org/LimitedAvailability',
            'unavailable', EventStateMachine::CANCELLED => 'https://schema.org/OutOfStock',
            default => 'https://schema.org/InStock',
        };
    }

    /**
     * Format datetime for schema.org.
     */
    private function formatDateTime(?string $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($datetime)->toIso8601String();
        } catch (\Exception) {
            return null;
        }
    }
}
