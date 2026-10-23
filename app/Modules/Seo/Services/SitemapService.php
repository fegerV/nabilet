<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Services\EventCanonicalUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Sitemap generation service (ТЗ §38).
 *
 * Generates XML sitemaps for search engines, split by content type to stay
 * under the 50,000 URL / 50 MB limits. Only published, visible content is
 * included — drafts and scheduled events are excluded.
 *
 * СЕМЬ КАРТ: события, статика, площадки, города, категории, организаторы и
 * артисты (последние пять — аудит P-12 и стратегия SEO B-1).
 * Раньше площадок здесь не было намеренно, и это
 * было верно: карта указывала на `route('venues.show')` — маршрут, которого не
 * существует, — поэтому эндпоинт отвечал 500, а если бы маршрут появился, карта
 * объявляла бы страницы, за которыми нет ничего. Карта добавляется ВМЕСТЕ со
 * страницей, а не раньше неё: адрес в карте — это обещание, что он отвечает 200.
 *
 * Карта категорий добавлена до карты организаторов, хотя категорий в рабочей базе
 * пока нет ни одной и CRUD-поверхности для них тоже нет. Причина та же: страница
 * `/category/{slug}` существует и отвечает 200, как только категория появится, а
 * карта обязана перечислять то, что открывается. Пустая карта при этом остаётся
 * корректным `<urlset>` — её присутствие в индексе ничего не стоит. Карта
 * организаторов, в отличие от неё, наполнена уже сейчас: организацию создаёт
 * установщик, без неё в системе нет ни одного события.
 *
 * Все семь карт собираются из ТОГО ЖЕ источника, которым пользуются сами
 * страницы (`CatalogService`), и включают только те коллекции, где есть хотя бы
 * одно публично видимое событие. Пустая посадочная страница отвечает 404
 * (`LandingController`), а адрес в карте, ведущий на 404, Google помечает как
 * ошибку и понижает доверие ко всей карте.
 */
class SitemapService
{
    /** Maximum URLs per sitemap file (Google limit) */
    private const MAX_URLS_PER_SITEMAP = 50_000;

    /** Cache TTL in seconds (1 hour) */
    private const CACHE_TTL = 3600;

    /** Default priority for events */
    private const DEFAULT_EVENT_PRIORITY = 0.8;

    /** High priority for recently published events */
    private const HIGH_EVENT_PRIORITY = 0.9;

    /** Приоритеты посадочных страниц: город — хаб раздела, площадка — уже него. */
    private const CITY_PRIORITY = 0.7;
    private const VENUE_PRIORITY = 0.6;

    /**
     * Категория — между городом и площадкой: она собирает события всех городов,
     * но её адрес не привязан к месту, поэтому она не «хаб раздела».
     */
    private const CATEGORY_PRIORITY = 0.65;

    /**
     * Организатор — на уровне площадки, и по той же причине: это СУЩНОСТЬ, а не
     * раздел. Его страница не собирает каталог места, она отвечает на вопрос
     * «кто проводит». Собирает он события всех своих городов — как категория, —
     * но категория описывает ЧТО, а организатор КТО, и спрос на «афишу
     * организатора» уже, чем на «афишу категории»: название организации ищут
     * те, кто её уже знает.
     */
    private const ORGANIZATION_PRIORITY = 0.6;

    /**
     * Артист — выше площадки, организатора и категории, на уровне города
     * (стратегия SEO, B-1).
     *
     * ПОЧЕМУ ВЫШЕ ОСТАЛЬНЫХ СУЩНОСТЕЙ. `priority` у Google не влияет на
     * ранжирование — это подсказка о важности СРЕДИ адресов одной карты, и
     * смысл у неё ровно один: какие страницы обходить первыми. Артист — самая
     * ценная из сущностей каталога по коммерческому намерению: «Би-2 билеты»
     * человек вводит, уже зная, что хочет купить, тогда как «афиша Казани» —
     * ещё выбор. Поэтому артист идёт впереди площадки и организатора, у которых
     * спрос формируется на месте.
     *
     * НЕ 1.0 И НЕ ВЫШЕ ГЛАВНОЙ. Единица оставлена каталогу (`getStaticPages()`):
     * витрина — точка входа для всего, и карта, ставящая сущность наравне с
     * корнем, сообщала бы краулеру, что корень не главнее карточки.
     */
    private const ARTIST_PRIORITY = 0.7;

    /**
     * Редакционная страница CMS — на уровне площадки/организатора
     * (стратегия SEO, B-2).
     *
     * Редакционная страница не продаёт билеты напрямую и не собирает события,
     * поэтому её приоритет в `sitemap` — НИЖЕ, чем у посадочной афиши. Это
     * подсказка краулеру «обходи реже»: редакционный контент меняется по
     * правке администратора, а не по событиям в каталоге.
     *
     * `changefreq` — `monthly`: редакционные страницы живут долго и
     * обновляются точечно. «weekly» обещало бы краулеру еженедельную смену
     * документа, который обычно не менялся.
     */
    private const PAGE_PRIORITY = 0.5;

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly EventCanonicalUrl $canonicalUrl,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function getEventsSitemap(array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $perPage = self::MAX_URLS_PER_SITEMAP;

        // СПИСОК КОЛОНОК ОБЯЗАН ВКЛЮЧАТЬ ВСЁ, ЧТО ЧИТАЕТ СБОРЩИК XML.
        //
        // Здесь стоял `['public_id', 'slug', 'updated_at', 'published_at']`, а
        // `generateEventsSitemap()` читает ещё `status` и `canonical_url`. Оба
        // поля отсутствовали в выборке, то есть всегда были `null`, и обе ветки
        // молча уходили в fallback:
        //
        //   - `canonical_url` из БД НИКОГДА не попадал в карту: адрес всегда
        //     вычислялся из slug. Администратор менял canonical и не видел
        //     эффекта — при том что в `<link rel="canonical">` на странице
        //     значение применялось (аудит P-14);
        //   - `status` не читался, поэтому приоритет считался как для
        //     незавершённого события: опубликованные получали 0.8 вместо 0.9.
        //
        // Ошибка не проявлялась никак — ни исключением, ни пустым ответом.
        // Именно поэтому она и прожила до аудита.
        return Event::query()
            ->whereIn('status', EventStatus::publiclyVisible())
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->paginate(
                $perPage,
                ['public_id', 'slug', 'status', 'canonical_url', 'updated_at', 'published_at'],
                'page',
                $page
            );
    }

    /**
     * Static pages that should be indexed.
     *
     * Only pages that actually exist. The storefront is a hash-routed SPA, so the
     * catalog is the one addressable static page — `about`, `privacy` and `terms`
     * have neither a server route nor a component, and calling `route()` on them
     * threw `RouteNotFoundException`, which is what made this endpoint answer 500.
     * Add them here together with the pages themselves, never before.
     *
     * Ключ `loc`, а не `url`: форма записи совпадает с остальными тремя картами
     * (`buildUrlset()`), поэтому сборщик XML читает один и тот же ключ во всех
     * четырёх. Промежуточное переименование `url` → `loc` было бы ровно тем
     * шагом, на котором одна из карт молча потеряла бы адреса.
     *
     * @return list<array{loc: string, lastmod: ?string, changefreq: string, priority: float}>
     */
    public function getStaticPages(): array
    {
        return [
            [
                'loc' => url('/'),
                // Дата правки СОДЕРЖИМОГО, а не момент ответа (аудит P-22).
                // Каталог — это список событий, поэтому он меняется тогда же,
                // когда они: `now()` здесь означал бы «страница изменилась
                // только что» при каждом обходе карты.
                'lastmod' => $this->latestContentUpdate(),
                'changefreq' => 'daily',
                'priority' => 1.0,
            ],
        ];
    }

    /**
     * Generate sitemap index pointing to individual sitemaps.
     *
     * `lastmod` дочерних карт — МОМЕНТ ИЗМЕНЕНИЯ ИХ СОДЕРЖИМОГО, а не `now()`
     * (аудит P-22). Раньше сюда подставлялся текущий момент, и получалось, что
     * каждая карта «изменилась только что» при каждом обходе — при том что XML
     * между сборками побайтово одинаков. Значение, которое всегда двигается,
     * Google классифицирует как недостоверное и перестаёт учитывать; по
     * спецификации sitemap.org `<lastmod>` в индексе — «когда изменился
     * соответствующий файл», то есть именно изменение, а не момент ответа.
     *
     * Источник даты — самая свежая правка среди событий, которые карта
     * перечисляет. Для карты статических страниц это тоже верно: её
     * единственная страница — каталог, и он меняется тогда же, когда события.
     */
    public function generateSitemapIndex(): string
    {
        $eventCount = Event::query()
            ->whereIn('status', EventStatus::publiclyVisible())
            ->whereNotNull('published_at')
            ->count();

        $sitemapCount = (int) ceil($eventCount / self::MAX_URLS_PER_SITEMAP);

        $contentChangedAt = $this->latestContentUpdate();

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $sitemapIndex = $xml->createElement('sitemapindex');
        $sitemapIndex->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Events sitemaps
        for ($i = 1; $i <= max(1, $sitemapCount); $i++) {
            $sitemap = $xml->createElement('sitemap');
            $loc = $xml->createElement('loc', route('sitemap.events', ['page' => $i]));
            $lastmod = $xml->createElement('lastmod', $contentChangedAt);

            $sitemap->appendChild($loc);
            $sitemap->appendChild($lastmod);
            $sitemapIndex->appendChild($sitemap);
        }

        // Static pages sitemap
        $staticSitemap = $xml->createElement('sitemap');
        $staticLoc = $xml->createElement('loc', route('sitemap.static'));
        $staticLastmod = $xml->createElement('lastmod', $contentChangedAt);
        $staticSitemap->appendChild($staticLoc);
        $staticSitemap->appendChild($staticLastmod);
        $sitemapIndex->appendChild($staticSitemap);

        // Посадочные страницы (аудит P-12, артисты — стратегия SEO B-1).
        // `lastmod` тот же, что у остальных: содержимое этих карт — те же
        // события, поэтому меняются они тогда же. Пустая карта площадок,
        // городов, категорий, организаторов или артистов остаётся в индексе:
        // это корректный пустой `<urlset>`, а не ошибка, и «то есть ли что
        // индексировать» — вопрос содержимого, а не наличия карты. Индекс,
        // меняющий состав записей в зависимости от данных, заставлял бы краулера
        // перечитывать его чаще, ничего не сообщая.
        //
        // Редакционные страницы (стратегия SEO B-2) идут в той же группе:
        // адреса `/page/{slug}` обслуживаются модулем Content, но sitemap
        // собирает Seo, потому что «что индексировать» — его предмет.
        foreach ([
            'sitemap.venues',
            'sitemap.cities',
            'sitemap.categories',
            'sitemap.organizers',
            'sitemap.artists',
            'sitemap.pages',
        ] as $routeName) {
            $sectionSitemap = $xml->createElement('sitemap');
            $sectionLoc = $xml->createElement('loc', route($routeName));
            $sectionLastmod = $xml->createElement('lastmod', $contentChangedAt);

            $sectionSitemap->appendChild($sectionLoc);
            $sectionSitemap->appendChild($sectionLastmod);
            $sitemapIndex->appendChild($sectionSitemap);
        }

        $xml->appendChild($sitemapIndex);

        return $xml->saveXML();
    }

    /**
     * Момент последней правки проиндексированного содержимого.
     *
     * `now()` — только когда событий нет вовсе: у пустого каталога даты
     * изменения не существует, а `<lastmod>` в `sitemapindex` по спецификации
     * необязателен, но его отсутствие хуже корректного значения.
     *
     * `updated_at`, а не `published_at`: администратор правит уже
     * опубликованное событие (цену, описание, афишу), и карта обязана это
     * заметить. Обоснование того, что `updated_at` здесь достоверен, — в
     * `lastmod()`.
     */
    private function latestContentUpdate(): string
    {
        $latest = Event::query()
            ->whereIn('status', EventStatus::publiclyVisible())
            ->whereNotNull('published_at')
            ->max('updated_at');

        if ($latest === null) {
            return now()->toIso8601String();
        }

        // Через `Carbon::parse`, потому что `max()` на сыром выражении отдаёт
        // строку MySQL (`2026-10-11 03:14:15.000000`), а не объект даты.
        $moment = \Carbon\Carbon::parse((string) $latest);

        return $moment->greaterThan(now()) ? now()->toIso8601String() : $moment->toIso8601String();
    }

    /**
     * Generate XML for events sitemap.
     *
     * @param Collection<int, Event> $events
     */
    public function generateEventsSitemap(Collection $events): string
    {
        $urls = [];

        foreach ($events as $event) {
            $urls[] = [
                'loc' => $this->getCanonicalUrl($event),
                'lastmod' => $this->lastmod($event),
                'changefreq' => $this->changeFrequency($event),
                // Priority based on status
                'priority' => $event->status === EventStatus::PUBLISHED
                    ? self::HIGH_EVENT_PRIORITY
                    : self::DEFAULT_EVENT_PRIORITY,
            ];
        }

        return $this->buildUrlset($urls);
    }

    /**
     * `lastmod` записи карты (аудит P-22).
     *
     * ЧТО ЗДЕСЬ БЫЛО НЕ ТАК. Значение бралось как
     * `($event->updated_at ?? $event->published_at) ?? now()` — без ограничений.
     * Два следствия, и оба обесценивают поле:
     *
     *   • `lastmod` мог оказаться В БУДУЩЕМ. Достаточно расхождения часов между
     *     веб-сервером и базой или строки, обновлённой с будущей датой. Google
     *     на будущую дату не «делает скидку»: он перестаёт доверять `lastmod`
     *     и игнорирует поле, причём правило у него на весь файл, а не на запись.
     *   • `lastmod` мог оказаться РАНЬШЕ `published_at` (администратор
     *     проставил дату публикации вручную). Тогда карта утверждала, что
     *     страница не менялась с момента, предшествующего её появлению.
     *
     * ПОЧЕМУ `updated_at` ВООБЩЕ ГОДИТСЯ. Аудит предполагает, что `updated_at`
     * двигают «служебные обновления, например триггеры инвентаря». Проверено по
     * `nabilet_core_spec/migrations.sql`: триггер в схеме ровно один и он на
     * `hall_schema_versions` — таблицу `events` не трогает ничто, кроме явного
     * `UPDATE` самой строки. А любая колонка `events` участвует в отрисовке
     * страницы (`title`, `description`, `poster`, `seo_*`, `robots`, `status`,
     * `slug`, `public_id` — всё это попадает в HTML, метатеги или разметку).
     * Значит `updated_at` — не «шум», а честная оценка момента изменения
     * страницы, и заменять его нечем: отдельной колонки «когда менялся контент»
     * в схеме нет, а эвристика по списку полей дала бы значение, которое
     * выглядит точным, но ошибается — то есть ровно то, из-за чего Google и
     * выбрасывает `lastmod` целиком.
     *
     * `null` в ответе означает «дату установить нечем»: тег не выводится, и
     * это корректнее выдуманного `now()`.
     */
    private function lastmod(Event $event): ?string
    {
        $candidate = $event->updated_at ?? $event->published_at;

        if ($candidate === null) {
            return null;
        }

        $now = now();

        // Будущее — не «свежая дата», а недостоверная. Отдаём текущий момент:
        // запись действительно изменилась не позже него.
        if ($candidate->greaterThan($now)) {
            $candidate = $now;
        }

        // Публикация — тоже изменение страницы, поэтому `lastmod` не может быть
        // раньше неё.
        if ($event->published_at !== null && $candidate->lessThan($event->published_at)) {
            $candidate = $event->published_at;
        }

        return $candidate->toIso8601String();
    }

    /**
     * `changefreq` записи — по статусу события (аудит P-22).
     *
     * Было захардкожено `weekly` для всего. Для ЗАВЕРШЁННОГО события это
     * заведомая неправда: страница прошедшего концерта не изменится больше
     * никогда — она архив. `yearly` сообщает именно это, и робот, уважающий
     * частоту (Яндекс и Bing её читают, Google — нет), перестаёт тратить на неё
     * краулинговый бюджет.
     *
     * Для опубликованного оставлено `weekly`: страница меняется не по расписанию
     * редактора, а по мере распродажи мест, и предсказать точнее нельзя.
     */
    private function changeFrequency(Event $event): string
    {
        return $event->status === EventStatus::PUBLISHED ? 'weekly' : 'yearly';
    }

    /**
     * Адрес страницы события для `<loc>`.
     *
     * ЧЕРЕЗ `EventCanonicalUrl`, А НЕ НАПРЯМУЮ ИЗ ПОЛЯ. Здесь стояло
     * `if (!empty($event->canonical_url)) return $event->canonical_url;` — то
     * есть ровно та строка, которую проверял на чужой домен контроллер
     * страницы, попадала в карту сайта без проверки. Последствие тяжелее, чем
     * на странице: `<loc>` обязан быть с того же хоста, что и сама карта
     * (протокол sitemap), иначе Search Console отклоняет карту целиком —
     * вместе со всеми остальными адресами в ней.
     */
    private function getCanonicalUrl(Event $event): string
    {
        return $this->canonicalUrl->for($event);
    }

    /**
     * Generate XML for static pages sitemap.
     */
    public function generateStaticSitemap(): string
    {
        return $this->buildUrlset($this->getStaticPages());
    }

    /**
     * Карта площадок (аудит P-12).
     *
     * Только площадки, у которых есть хотя бы одно публично видимое событие:
     * остальные отвечают 404 (`LandingController`), а адрес в карте, ведущий на
     * 404, Google помечает как ошибку и понижает доверие ко всей карте. Это
     * ровно та причина, по которой карта площадок когда-то была удалена, и
     * теперь она закрыта не удалением, а фильтром.
     */
    public function generateVenuesSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->venues() as $venue) {
            if ($venue['count'] < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('landing.venue', ['slug' => $venue['slug']]),
                'lastmod' => $this->normalizedMoment($venue['last_change']),
                // Площадка — это один адрес: её содержимое меняется, когда
                // продаются места, но не каждый день.
                'changefreq' => 'weekly',
                'priority' => self::VENUE_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Карта городов (аудит P-12).
     *
     * `changefreq` здесь `daily`, в отличие от площадок: страница города
     * собирает весь каталог места, поэтому меняется при любом изменении любого
     * события в нём — это самый подвижный уровень после главной.
     */
    public function generateCitiesSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->cities() as $city) {
            if ($city['count'] < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('landing.city', ['slug' => $city['slug']]),
                'lastmod' => $this->normalizedMoment($city['last_change']),
                'changefreq' => 'daily',
                'priority' => self::CITY_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Карта категорий (аудит P-12).
     *
     * Только категории, у которых есть хотя бы одно публично видимое событие, —
     * по той же причине, что у площадок и городов: пустая страница категории
     * отвечает 404, а адрес в карте, ведущий на 404, Google помечает как ошибку
     * и понижает доверие ко всей карте.
     *
     * `changefreq` — `weekly`, а не `daily` как у города: страница категории
     * собирает события ВСЕХ городов, но набор её событий меняется не от каждой
     * правки любого события, а от попадания события в категорию. Обещать `daily`
     * значило бы звать робота ежедневно за документом, который обычно не
     * изменился.
     */
    public function generateCategoriesSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->categories() as $category) {
            if ($category['count'] < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('landing.category', ['slug' => $category['slug']]),
                'lastmod' => $this->normalizedMoment($category['last_change']),
                'changefreq' => 'weekly',
                'priority' => self::CATEGORY_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Карта организаторов (аудит P-12).
     *
     * Только организаторы с хотя бы одним публично видимым событием — по той же
     * причине, что у площадок, городов и категорий: страница организатора без
     * событий отвечает 404, а адрес в карте, ведущий на 404, Google помечает как
     * ошибку и понижает доверие ко всей карте.
     *
     * ЭТА КАРТА, В ОТЛИЧИЕ ОТ КАРТЫ КАТЕГОРИЙ, НАПОЛНЕНА СРАЗУ: организация
     * создаётся установщиком, и без неё в системе нет ни одного события.
     *
     * `changefreq` — `weekly`, как у площадки: набор событий организатора
     * меняется от попадания в него события, а не от каждой правки любого из них.
     */
    public function generateOrganizersSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->organizations() as $organization) {
            if ($organization['count'] < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('landing.organizer', ['slug' => $organization['slug']]),
                'lastmod' => $this->normalizedMoment($organization['last_change']),
                'changefreq' => 'weekly',
                'priority' => self::ORGANIZATION_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Карта артистов (стратегия SEO, B-1) — `/sitemap-artists.xml`.
     *
     * Только артисты с хотя бы одним публично видимым событием — по той же
     * причине, что у площадок, городов, категорий и организаторов: страница
     * артиста без событий отвечает 404, а адрес в карте, ведущий на 404, Google
     * помечает как ошибку и понижает доверие ко всей карте.
     *
     * ЭТА КАРТА — САМАЯ ЧУВСТВИТЕЛЬНАЯ К ПУСТОТЕ, и вот почему. Артист
     * появляется в ней не от того, что администратор что-то завёл вручную, а от
     * результата `artists:sync` по свободному тексту `event_artists`. Пока
     * команда не запускалась, `artists` пуста, и карта — корректный пустой
     * `<urlset>`. Это не ошибка: карта перечисляет то, что открывается, а
     * открывается ровно то, что свела команда. Ложная строка появилась бы,
     * если бы карта читала `event_artists` напрямую, — тогда адрес
     * `/artist/би-2` вёл бы на 404, потому что такой записи в `artists` нет.
     *
     * `changefreq` — `weekly`, как у площадки и организатора: набор событий
     * артиста меняется от попадания в него события, а не от каждой правки
     * любого из них.
     */
    public function generateArtistsSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->artists() as $artist) {
            if ($artist['count'] < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('landing.artist', ['slug' => $artist['slug']]),
                'lastmod' => $this->normalizedMoment($artist['last_change']),
                'changefreq' => 'weekly',
                'priority' => self::ARTIST_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Карта редакционных страниц CMS — `/sitemap-pages.xml` (стратегия SEO, B-2).
     *
     * Только опубликованные страницы с `published_at <= now()`, по той же
     * паре ключей, что `PageService::forSlug()`: расхождение между картой и
     * страницей — это 404 из sitemap'а. Пустая карта остаётся корректным
     * `<urlset>`, пока ни одной страницы не опубликовано: редакционный контент
     * ещё не написан, и это нормальное состояние на свежей установке.
     */
    public function generatePagesSitemap(): string
    {
        $urls = [];

        foreach ($this->catalog->pages() as $page) {
            $urls[] = [
                'loc' => route('content.page', ['slug' => $page['slug']]),
                'lastmod' => $this->normalizedMoment($page['last_change']),
                'changefreq' => 'monthly',
                'priority' => self::PAGE_PRIORITY,
            ];
        }

        return $this->buildUrlset($this->cap($urls));
    }

    /**
     * Сборка `<urlset>` из готовых записей.
     *
     * Одна точка сборки на четыре карты. Раньше каждая карта собирала DOM сама,
     * и расхождение уже началось: в карте событий `lastmod` выводился всегда, а
     * `lastmod()` умеет возвращать `null` (у события без `updated_at` и
     * `published_at` даты нет) — то есть в XML уезжал `<lastmod/>` без значения
     * или deprecation от `createElement(null)`. Здесь необязательные теги просто
     * не выводятся, и это одинаково для всех карт.
     *
     * `loc` печатается всегда: запись без адреса бессмысленна, и её лучше не
     * добавлять вовсе (это делает вызывающий код), чем отдать карту с пустым
     * `<loc>`.
     *
     * @param  list<array{loc: string, lastmod?: ?string, changefreq?: ?string, priority?: float|int|null}>  $urls
     */
    private function buildUrlset(array $urls): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($urls as $entry) {
            $url = $xml->createElement('url');
            $url->appendChild($xml->createElement('loc', $entry['loc']));

            // `<lastmod>` необязателен по спецификации sitemap.org, и его
            // отсутствие корректнее выдуманной даты (см. `lastmod()`).
            if (($entry['lastmod'] ?? null) !== null) {
                $url->appendChild($xml->createElement('lastmod', (string) $entry['lastmod']));
            }

            if (($entry['changefreq'] ?? null) !== null) {
                $url->appendChild($xml->createElement('changefreq', (string) $entry['changefreq']));
            }

            if (($entry['priority'] ?? null) !== null) {
                $url->appendChild($xml->createElement('priority', (string) $entry['priority']));
            }

            $urlset->appendChild($url);
        }

        $xml->appendChild($urlset);

        return $xml->saveXML();
    }

    /**
     * Предохранитель на предел одного файла карты.
     *
     * События разбиты на страницы (`?page=N`), потому что их может быть много.
     * Площадок и городов на порядки меньше, поэтому они собираются в один файл —
     * но обрезка остаётся: файл с более чем 50 000 адресов Google отвергает
     * ЦЕЛИКОМ, и молча получить невалидную карту хуже, чем отдать её часть.
     * Если площадки когда-нибудь подойдут к пределу, их нужно разбить так же,
     * как события, а не полагаться на этот срез.
     *
     * @param  list<array{loc: string, lastmod?: ?string, changefreq?: ?string, priority?: float|int|null}>  $urls
     * @return list<array{loc: string, lastmod?: ?string, changefreq?: ?string, priority?: float|int|null}>
     */
    private function cap(array $urls): array
    {
        return array_slice($urls, 0, self::MAX_URLS_PER_SITEMAP);
    }

    /**
     * `lastmod` посадочной страницы: дата правки, не в будущем.
     *
     * Значение приходит из SQL как строка (`MAX(updated_at)`), поэтому
     * разбирается через `Carbon::parse`. Правило то же, что у `lastmod()`
     * карты событий (аудит P-22): дата в будущем обесценивает поле во ВСЁМ
     * файле, а не в одной записи.
     */
    private function normalizedMoment(?string $moment): ?string
    {
        if ($moment === null || trim($moment) === '') {
            return null;
        }

        try {
            $value = \Carbon\Carbon::parse(trim($moment));
        } catch (\Exception) {
            return null;
        }

        return $value->greaterThan(now()) ? now()->toIso8601String() : $value->toIso8601String();
    }

    // ── Кешированные точки входа (аудит P-10) ───────────────────────────────
    //
    // ЗАЧЕМ. `CACHE_TTL` существовал в этом классе с самого начала и не
    // использовался НИ РАЗУ: каждый запрос краулера заново считал `COUNT(*)` по
    // `events`, затем выбирал страницу событий и собирал XML. Поисковики обходят
    // карту регулярно и не по одному разу, поэтому документ, статический по
    // смыслу, превращался в работу с базой на каждый заход.
    //
    // ПОЧЕМУ `Cache::remember`, А НЕ ТОЛЬКО `Cache-Control`. HTTP-кеш работает
    // лишь у дисциплинированного клиента: он не спасает ни от нескольких
    // краулеров сразу, ни от запроса с `Cache-Control: no-cache`, ни от ручной
    // проверки в Search Console. Кеш на стороне приложения закрывает все эти
    // случаи; заголовок остаётся как дополнение для прокси.
    //
    // ЦЕНА РЕШЕНИЯ — задержка до часа: событие, опубликованное сразу после
    // сборки карты, появится в ней при следующем промахе кеша. Для карты сайта
    // это нормально (краулер всё равно не переобойдёт её мгновенно), а страница
    // события доступна сразу — карта лишь подсказка, а не источник истины.
    //
    // ХРАНИЛИЩЕ — `cache.default`. В проекте это `database` (см.
    // `ProductionSafetyProvider`), и для каталога в десятки тысяч событий XML
    // весит единицы мегабайт: это ещё приемлемо для `MEDIUMTEXT`, но при росте
    // каталога карту стоит вынести в файловый или Redis-стор.

    /** XML карты-индекса из кеша. */
    public function cachedSitemapIndex(): string
    {
        return Cache::remember(
            'seo:sitemap:index',
            self::CACHE_TTL,
            fn (): string => $this->generateSitemapIndex(),
        );
    }

    /** XML карты событий для страницы `$page` из кеша. */
    public function cachedEventsSitemap(int $page = 1): string
    {
        $page = max(1, $page);

        return Cache::remember(
            'seo:sitemap:events:' . $page,
            self::CACHE_TTL,
            fn (): string => $this->generateEventsSitemap(
                $this->getEventsSitemap([], $page)->getCollection()
            ),
        );
    }

    /** XML карты статических страниц из кеша. */
    public function cachedStaticSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:static',
            self::CACHE_TTL,
            fn (): string => $this->generateStaticSitemap(),
        );
    }

    /** XML карты площадок из кеша. */
    public function cachedVenuesSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:venues',
            self::CACHE_TTL,
            fn (): string => $this->generateVenuesSitemap(),
        );
    }

    /** XML карты городов из кеша. */
    public function cachedCitiesSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:cities',
            self::CACHE_TTL,
            fn (): string => $this->generateCitiesSitemap(),
        );
    }

    /** XML карты категорий из кеша. */
    public function cachedCategoriesSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:categories',
            self::CACHE_TTL,
            fn (): string => $this->generateCategoriesSitemap(),
        );
    }

    /** XML карты организаторов из кеша. */
    public function cachedOrganizersSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:organizers',
            self::CACHE_TTL,
            fn (): string => $this->generateOrganizersSitemap(),
        );
    }

    /** XML карты артистов из кеша. */
    public function cachedArtistsSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:artists',
            self::CACHE_TTL,
            fn (): string => $this->generateArtistsSitemap(),
        );
    }

    /** XML карты редакционных страниц CMS из кеша (стратегия SEO, B-2). */
    public function cachedPagesSitemap(): string
    {
        return Cache::remember(
            'seo:sitemap:pages',
            self::CACHE_TTL,
            fn (): string => $this->generatePagesSitemap(),
        );
    }
}
