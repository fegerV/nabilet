<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Content\Domain\PageStatus;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Artist;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Models\EventCategory;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Venue;

/**
 * Модель чтения каталога для посадочных страниц (аудит P-12).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС, А НЕ ФИЛЬТРЫ ВИТРИНЫ. Публичный каталог
 * (`GET /api/v1/events`) отдаёт СПИСОК, и его фильтры применяются к нему же:
 * `city` и `category` в `ListEventsRequest` объявлены, но контроллер их не
 * читает. Посадочной странице нужно другое: не «все события», а «события одной
 * площадки/города, упорядоченные по ближайшей дате, с ценой от». Это отдельный
 * запрос с отдельными требованиями, и попытка выразить его через фильтры списка
 * тянула бы в публичный API параметры, которые там не нужны.
 *
 * ПОЧЕМУ ЗДЕСЬ ПРЯМЫЕ ЗАПРОСЫ К `events`/`venues`/`sessions`. Модуль Seo уже
 * читает модель `Event` (`SitemapService`), потому что «что индексировать» —
 * его предмет. Посадочные страницы — ровно тот же предмет, только вместо
 * перечисления адресов он собирает их содержимое.
 *
 * ДВА РАЗНЫХ ТИПА КОЛЛЕКЦИЙ, И РАЗНИЦА МЕЖДУ НИМИ НЕ КОСМЕТИЧЕСКАЯ. Место
 * (площадка, город) отвечает на вопрос «что здесь идёт» — событие без сеанса там
 * показать нечего, поэтому `eventsAtVenue()` и `eventsInCity()` требуют сеанс.
 * Фасет каталога (категория, организатор) отвечает на вопрос «что у нас есть
 * такого» — и его набор обязан совпадать с набором публичного каталога
 * (`GET /api/v1/events`), который фильтруется только по статусу. Оба ключа,
 * которыми каталог умеет фильтровать (`organization_id` и `category_id`, см.
 * `EventRepository::applyFilters()`), поэтому и стали посадочными страницами:
 * `eventsInCategory()` и `eventsAtOrganization()` сеанс НЕ требуют. Требование
 * сеанса сделало бы их набор уже каталога, то есть расхождение между двумя
 * представлениями одних данных.
 *
 * ПУБЛИЧНОСТЬ ЗДЕСЬ ОПРЕДЕЛЯЕТСЯ СТАТУСОМ, И ЭТО ОСОЗНАННО МЯГЧЕ КАРТЫ САЙТА.
 * `SitemapService` дополнительно требует заполненный `published_at` не из
 * будущего. Посадочная страница так не делает: это навигационная поверхность, и
 * событие, у которого администратор не проставил дату публикации, обязано
 * остаться видимым в городе — исчезновение из списка по причине, которую в
 * админке не видно, выглядит как пропавшее мероприятие. Набор статусов при этом
 * ТОТ ЖЕ (`EventStatus::publiclyVisible()`), что у публичного API: расхождение
 * здесь означало бы, что событие есть в списке каталога и отсутствует в городе.
 */
class CatalogService
{
    /** Сколько записей на странице коллекции. */
    public const PER_PAGE = 20;

    /** Сколько соседних коллекций выводится в перелинковке. */
    public const RELATED_LIMIT = 8;

    /**
     * Минимальная цена среди ДОСТУПНОГО инвентаря сеансов события.
     *
     * Скалярным подзапросом, а не предзагрузкой `sessions.inventoryItems`.
     * Разница не косметическая: у события бывает несколько сеансов, у сеанса —
     * сотни мест, и предзагрузка ради одного числа тянула бы на страницу с 20
     * событиями тысячи строк. Условие `available_quantity > 0` совпадает с тем,
     * по которому цену считает `EventPageController::offerPayload()`: «от N ₽» в
     * списке и цена в разметке страницы события обязаны совпадать.
     *
     * Цена берётся по ВСЕМ сеансам события, хотя дата показывается по
     * ближайшему. Это и есть смысл «от»: покупатель читает «от 2 500 ₽» как
     * «дешевле 2 500 ₽ тут не бывает», и это верно для любого сеанса.
     */
    private const MIN_PRICE_SQL = '(SELECT MIN(ii.price_amount)'
        . ' FROM inventory_items ii'
        . ' INNER JOIN sessions si ON si.id = ii.session_id'
        . ' WHERE si.event_id = events.id'
        . ' AND ii.status = ?'
        . ' AND ii.available_quantity > 0)';

    /**
     * Площадка по глобально уникальному slug.
     *
     * `status = 'active'` — часть условия, а не украшение: страница неактивной
     * площадки не должна открываться, иначе в индексе окажется адрес сущности,
     * которую администратор снял с публикации.
     *
     * ХРУПКОСТЬ, КОТОРУЮ НУЖНО ЗНАТЬ (аудит P-03). Здесь раньше стояло «slug
     * глобально уникален (`uq_venues_slug`)» — и это неверно: в схеме объявлен
     * составной `uq_venues_org_slug` по `(organization_id, slug)`, то есть slug
     * площадки уникален ВНУТРИ организации, а не в базе. Пока клиент один,
     * `first()` однозначен и разницы нет; со вторым клиентом две организации
     * смогут завести площадки с одинаковым slug, и `first()` начнёт возвращать
     * произвольную из двух. Тогда этой выборке понадобится организация —
     * ровно то, что описывает P-03 (префикс `/{orgSlug}/…` или субдомен).
     * Запись оставлена как предупреждение: молчаливая «уникальность», которой
     * в схеме нет, — это ровно тот случай, когда баг приходит с данными.
     */
    public function venueBySlug(string $slug): ?Venue
    {
        return Venue::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Город по slug — вместе со всеми написаниями, которые в него отображаются.
     *
     * ПОЧЕМУ «НАПИСАНИЯ», А НЕ ОДНО ИМЯ. Города в схеме нет: `venues.city` —
     * свободный текст, который администратор вводит руками. «Санкт-Петербург» и
     * «Санкт Петербург» дают один slug (`sankt-peterburg`) и должны открывать
     * одну страницу — иначе половина площадок выпадает из города, а поисковик
     * получает две страницы-полудубля. Поэтому фильтр по городу идёт по СПИСКУ
     * написаний, а не по одному значению.
     *
     * Каноническим считается написание с наибольшим числом площадок: это и есть
     * то, как город называют на самом деле, а не случайная опечатка.
     *
     * @return array{name: string, slug: string, names: list<string>, count: int}|null
     */
    public function cityBySlug(string $slug): ?array
    {
        foreach ($this->cities() as $city) {
            if ($city['slug'] === $slug) {
                return $city;
            }
        }

        return null;
    }

    /**
     * Категория по глобально уникальному slug.
     *
     * Slug категории уникален в масштабе таблицы (`uq_event_categories_slug`),
     * поэтому выборка однозначна. `status = 'active'` — часть условия: страница
     * снятой с публикации категории не должна открываться, иначе в индексе
     * остаётся адрес сущности, которую администратор закрыл.
     */
    public function categoryBySlug(string $slug): ?EventCategory
    {
        return EventCategory::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Организатор по глобально уникальному slug.
     *
     * Slug организации уникален в масштабе таблицы (`uq_organizations_slug`),
     * поэтому выборка однозначна. `status = 'active'` — часть условия по той же
     * причине, что у площадки и категории: страница снятого с публикации
     * организатора не должна открываться, иначе в индексе остаётся адрес
     * сущности, которую администратор закрыл.
     *
     * КЛАССОВ С ИМЕНЕМ `Organization` В ПРОЕКТЕ ДВА, И ЭТО ЛОВУШКА.
     * `Nabilet\Modules\Organizations\Models\Organization` — дубликат-наследие,
     * на который больше не ссылается никто, кроме трёх моделей Tickets; на нём
     * стоял трейт `SoftDeletes`, а колонки `organizations.deleted_at` в схеме НЕТ
     * (ни в `2026_09_20_000100_001_identity.php`, ни в спецификации). Трейт
     * добавлял `where organizations.deleted_at is null` в КАЖДЫЙ запрос, и любой
     * запрос через ту модель падал с `SQLSTATE[42S22] Column not found: 1054`.
     * Поэтому импорт здесь идёт от канонической реализации — той же, что у
     * двадцати с лишним файлов проекта.
     *
     * Мягкого удаления у организаций нет вовсе: строку снимают с публикации
     * статусом, а удаляют по-настоящему, когда у неё не осталось событий.
     */
    public function organizationBySlug(string $slug): ?Organization
    {
        return Organization::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Артист по глобально уникальному slug (стратегия SEO, B-1).
     *
     * Slug артиста уникален в масштабе таблицы (`uq_artists_slug`), поэтому
     * выборка однозначна. `status = 'active'` — часть условия, а не украшение:
     * страница снятого с публикации артиста не должна открываться, иначе в
     * индексе остаётся адрес сущности, которую администратор закрыл. То же
     * правило, что у площадки, категории и организатора.
     *
     * СВЯЗЬ С СОБЫТИЯМИ ИДЁТ ЧЕРЕЗ `artist_event`, А НЕ ЧЕРЕЗ `event_artists`.
     * Имена артистов в `event_artists` хранятся свободным текстом внутри
     * события, и «Би-2» с «БИ-2» — две разные строки. `artist_event` собирает
     * все написания в одну запись, и именно поэтому у артиста может быть
     * страница: без слияния она была бы по одной на каждое написание. Подробнее
     * — в докблоках `Artist` и `SyncArtistsCommand`.
     */
    public function artistBySlug(string $slug): ?Artist
    {
        return Artist::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Все активные артисты с числом публично видимых событий (стратегия SEO, B-1).
     *
     * Список читают двое: перелинковка страницы артиста (соседние артисты) и
     * карта сайта (какие адреса вообще существуют). Разрешение ОДНОГО slug —
     * это отдельный `artistBySlug()`; здесь список нужен целиком. Один запрос на
     * оба чтения — как у городов, категорий и организаторов, потому что
     * расхождение между «страницей, которая открывается» и «адресом в карте» —
     * это 404 из sitemap.
     *
     * LEFT JOIN ДВАЖДЫ, и оба раза неслучайно. Первый (`artist_event`) —
     * потому что артист без событий обязан остаться в списке: иначе он не
     * найдётся по slug, и страница ответит 404 вместо «здесь пока нет
     * мероприятий», а 404 обесценивает уже проиндексированный адрес. Второй
     * (`events`) — с фильтром публичности в самом `ON`, а не в `WHERE`: фильтр в
     * `WHERE` превратил бы LEFT JOIN в INNER и выбросил бы артистов без
     * видимых событий, то есть вернул бы ровно ту ошибку, которую убирает
     * первый join.
     *
     * `deleted_at` У `events` ФИЛЬТРУЕТСЯ ВРУЧНУЮ. Запрос идёт через
     * `DB::table()`, а сырой конструктор про трейт `SoftDeletes` не знает, —
     * без `whereNull` мягко удалённое событие продолжало бы наполнять артиста.
     * У `artists` такой колонки нет вовсе (как и у `event_categories` и
     * `organizations`): артиста снимают с публикации статусом, а не удалением.
     *
     * @return list<array{name: string, slug: string, count: int, last_change: ?string}>
     */
    public function artists(): array
    {
        $rows = DB::table('artists')
            ->leftJoin('artist_event', 'artist_event.artist_id', '=', 'artists.id')
            ->leftJoin('events', function ($join): void {
                $join->on('events.id', '=', 'artist_event.event_id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    ->whereNull('events.deleted_at');
            })
            ->where('artists.status', 'active')
            ->groupBy(
                'artists.id',
                'artists.name',
                'artists.slug',
                'artists.updated_at',
            )
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('artists.name')
            ->get([
                'artists.name as name',
                'artists.slug as slug',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                // `GREATEST` из правки самой карточки артиста и правок его
                // событий — тем же правилом, что у площадки и организатора:
                // страница артиста состоит из обоих, поэтому меняется при
                // изменении любого.
                DB::raw('GREATEST(artists.updated_at, COALESCE(MAX(events.updated_at), artists.updated_at)) as last_change'),
            ]);

        return array_map(static fn ($row): array => [
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'count' => (int) $row->event_count,
            'last_change' => $row->last_change === null ? null : (string) $row->last_change,
        ], $rows->all());
    }

    /**
     * Все активные организаторы с числом публично видимых событий.
     *
     * Список читают двое: страница организатора (разрешение slug) и карта сайта
     * (какие адреса существуют). Один запрос на оба — как у городов и категорий,
     * потому что расхождение между «страницей, которая открывается» и «адресом в
     * карте» — это 404 из sitemap.
     *
     * LEFT JOIN, а не INNER: организатор без событий обязан остаться в списке.
     * Иначе он не найдётся по slug, и страница ответит 404 вместо «здесь пока нет
     * мероприятий», а 404 обесценивает уже проиндексированный адрес.
     *
     * ФИЛЬТРА `deleted_at` ЗДЕСЬ НЕТ, И ЭТО НЕ ЗАБЫВЧИВОСТЬ. У `organizations`
     * такой колонки в схеме нет вовсе (проверено на живой базе: `deleted_at` есть
     * у `events`, `media_assets`, `promo_codes`, `users` и `event_dates`, но не у
     * `organizations`), поэтому запрос с ним падает на `SQLSTATE[42S22]`.
     * У организаций мягкого удаления нет: с публикации снимают статусом, а
     * удаляют по-настоящему. У `events` внутри JOIN фильтр, наоборот, обязателен —
     * там колонка есть, а сырой запрос про `SoftDeletes` не знает.
     *
     * @return list<array{name: string, slug: string, count: int, last_change: ?string}>
     */
    public function organizations(): array
    {
        $rows = DB::table('organizations')
            ->leftJoin('events', function ($join): void {
                $join->on('events.organization_id', '=', 'organizations.id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    ->whereNull('events.deleted_at');
            })
            ->where('organizations.status', 'active')
            ->groupBy(
                'organizations.id',
                'organizations.name',
                'organizations.slug',
                'organizations.updated_at',
            )
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('organizations.name')
            ->get([
                'organizations.name as name',
                'organizations.slug as slug',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                // `GREATEST` из правки самой организации и правок её событий —
                // тем же правилом, что у площадки: страница организатора состоит
                // из обоих, поэтому меняется при изменении любого.
                DB::raw('GREATEST(organizations.updated_at, COALESCE(MAX(events.updated_at), organizations.updated_at)) as last_change'),
            ]);

        return array_map(static fn ($row): array => [
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'count' => (int) $row->event_count,
            'last_change' => $row->last_change === null ? null : (string) $row->last_change,
        ], $rows->all());
    }

    /**
     * Опубликованные редакционные страницы CMS (стратегия SEO, B-2).
     *
     * Простой список без агрегации событий: страница CMS — это самостоятельный
     * документ, а не фасет каталога. `published_at` обязателен и не из
     * будущего — по той же паре ключей, что `PageService::forSlug()`: расхождение
     * между картой и страницей — это 404 из sitemap'а.
     *
     * `last_change` — `updated_at` самой страницы. У редакционной страницы нет
     * связанных событий, которые могли бы на неё влиять, поэтому правило
     * `GREATEST(свой, MAX(events))` (как у площадки или организатора) тут не
     * работает: `events.updated_at` к странице не имеет отношения.
     *
     * @return list<array{slug: string, last_change: ?string}>
     */
    public function pages(): array
    {
        $rows = DB::table('pages')
            ->where('status', PageStatus::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at']);

        return array_map(static fn ($row): array => [
            'slug' => (string) $row->slug,
            'last_change' => $row->updated_at === null ? null : (string) $row->updated_at,
        ], $rows->all());
    }

    /**
     * Все активные категории с числом публично видимых событий.
     *
     * `event_categories` не имеет временных меток (`public $timestamps = false`,
     * и в схеме нет ни `created_at`, ни `updated_at`), поэтому дату изменения
     * взять неоткуда, кроме событий: содержимое страницы категории — это ровно
     * список её событий, и меняется оно тогда же, когда они.
     *
     * @return list<array{id: int, name: string, slug: string, count: int, last_change: ?string}>
     */
    public function categories(): array
    {
        $rows = DB::table('event_categories')
            // LEFT JOIN, а не INNER: категория без событий обязана остаться в
            // списке. Иначе она не найдётся по slug, и страница категории
            // ответит 404 вместо «здесь пока нет мероприятий» — а 404
            // обесценивает уже проиндексированный адрес.
            ->leftJoin('events', function ($join): void {
                $join->on('events.category_id', '=', 'event_categories.id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    // Мягко удалённое событие остаётся в таблице: сырой запрос
                    // не знает про SoftDeletes, фильтр обязателен вручную.
                    ->whereNull('events.deleted_at');
            })
            ->where('event_categories.status', 'active')
            ->groupBy('event_categories.id', 'event_categories.name', 'event_categories.slug')
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('event_categories.name')
            ->get([
                'event_categories.id as id',
                'event_categories.name as name',
                'event_categories.slug as slug',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                DB::raw('MAX(events.updated_at) as last_change'),
            ]);

        return array_map(static fn ($row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'count' => (int) $row->event_count,
            'last_change' => $row->last_change === null ? null : (string) $row->last_change,
        ], $rows->all());
    }

    /**
     * Категории, в которых есть события этого города, с числом этих событий.
     *
     * Нужен для перелинковки со страницы «город × категория»: соседями там
     * должны быть ДРУГИЕ КАТЕГОРИИ ТОГО ЖЕ ГОРОДА, а не категории вообще.
     * Показать рядом с ссылкой «Театр в Москве» общероссийское число событий
     * означало бы сообщить читателю неправду о том, куда ведёт ссылка.
     *
     * INNER JOIN здесь, в отличие от `categories()`, уместен: категория без
     * событий в этом городе соседом быть не может — её страница в этом городе
     * отвечает 404.
     *
     * @param  list<string>  $cityNames
     * @return list<array{id: int, name: string, slug: string, count: int, last_change: ?string}>
     */
    public function categoriesInCity(array $cityNames): array
    {
        $rows = DB::table('event_categories')
            ->join('events', function ($join): void {
                $join->on('events.category_id', '=', 'event_categories.id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    ->whereNull('events.deleted_at');
            })
            ->join('sessions', 'sessions.event_id', '=', 'events.id')
            ->join('venues', 'venues.id', '=', 'sessions.venue_id')
            ->where('event_categories.status', 'active')
            ->whereIn('venues.city', $cityNames)
            ->groupBy('event_categories.id', 'event_categories.name', 'event_categories.slug')
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('event_categories.name')
            ->get([
                'event_categories.id as id',
                'event_categories.name as name',
                'event_categories.slug as slug',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                DB::raw('MAX(events.updated_at) as last_change'),
            ]);

        return array_map(static fn ($row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'count' => (int) $row->event_count,
            'last_change' => $row->last_change === null ? null : (string) $row->last_change,
        ], $rows->all());
    }

    /**
     * Все города площадок с числом публично видимых событий.
     *
     * Список читают двое: страница города (разрешение slug → написания) и карта
     * сайта (какие адреса вообще существуют). Один запрос на оба, потому что
     * второй вызов получил бы тот же результат, а расхождение между «городом,
     * который открывается» и «городом, который в карте» — это 404 из sitemap.
     *
     * Порядок — по числу событий убыванию: он же задаёт перелинковку, а
     * ссылаться полезнее на города, где что-то есть.
     *
     * ХРУПКОСТЬ, О КОТОРОЙ НУЖНО ЗНАТЬ: адрес города ПРОИЗВОДИТСЯ из его
     * написания через `Str::slug()`, то есть транслитерация (`Тюмень` →
     * `tiumen`, `Брянск` → `briansk`) приходит из `voku/portable-ascii`.
     * Обновление этой библиотеки сменит адреса у ВСЕХ городов сразу, а старые
     * адреса из индекса начнут отвечать 404. Заметить это можно только тестом,
     * в котором адрес написан явно (`LandingPageTest`), — поэтому он там и
     * написан явно, а не через `Str::slug()`. Смягчение, если оно когда-нибудь
     * понадобится: таблица городов с колонкой slug плюс редиректы со старых
     * адресов, как это уже сделано для событий (`Seo\Services\RedirectResolver`).
     *
     * @return list<array{name: string, slug: string, names: list<string>, count: int, last_change: ?string}>
     */
    public function cities(): array
    {
        $rows = DB::table('venues')
            // LEFT JOIN, а не INNER: город без событий обязан остаться в списке.
            // Иначе он не найдётся по slug, и страница города ответит 404 вместо
            // «здесь пока нет мероприятий» — а это разные вещи и для человека, и
            // для робота (404 обесценивает уже проиндексированный адрес).
            ->leftJoin('sessions', 'sessions.venue_id', '=', 'venues.id')
            ->leftJoin('events', function ($join): void {
                $join->on('events.id', '=', 'sessions.event_id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    // Мягко удалённое событие остаётся в таблице, поэтому фильтр
                    // обязателен вручную: сырой запрос не знает про SoftDeletes,
                    // и удалённое мероприятие иначе продолжало бы наполнять город.
                    ->whereNull('events.deleted_at');
            })
            ->where('venues.status', 'active')
            ->whereNotNull('venues.city')
            ->where('venues.city', '<>', '')
            ->groupBy('venues.city')
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('venues.city')
            ->get([
                'venues.city as city',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                // Дата правки содержимого города. Своего `updated_at` у города
                // нет (города как сущности не существует), поэтому его
                // содержимое меняется тогда же, когда события в нём.
                DB::raw('MAX(events.updated_at) as last_change'),
            ]);

        $bySlug = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->city);
            $slug = Str::slug($name);

            if ($slug === '') {
                continue;
            }

            if (! isset($bySlug[$slug])) {
                $bySlug[$slug] = [
                    'name' => $name,
                    'slug' => $slug,
                    'names' => [],
                    'count' => 0,
                    'last_change' => null,
                ];
            }

            $bySlug[$slug]['names'][] = $name;
            $bySlug[$slug]['count'] += (int) $row->event_count;

            // Строки идут по убыванию числа событий, поэтому первое написание
            // для slug'а — самое употребительное, и оно уже стало `name`.
            // Дату берём максимальную: город изменился, когда изменился любой
            // его вариант написания.
            if ($row->last_change !== null) {
                $current = $bySlug[$slug]['last_change'];
                $bySlug[$slug]['last_change'] = $current === null
                    ? (string) $row->last_change
                    : max($current, (string) $row->last_change);
            }
        }

        return array_values($bySlug);
    }

    /**
     * Все активные площадки с числом публично видимых событий.
     *
     * `last_change` — максимум из правки самой площадки и правок её событий:
     * страница площадки состоит из обоих, поэтому меняется при изменении любого.
     *
     * @return list<array{name: string, slug: string, city: ?string, count: int, last_change: ?string}>
     */
    public function venues(): array
    {
        $rows = DB::table('venues')
            ->leftJoin('sessions', 'sessions.venue_id', '=', 'venues.id')
            ->leftJoin('events', function ($join): void {
                $join->on('events.id', '=', 'sessions.event_id')
                    ->whereIn('events.status', EventStatus::publiclyVisible())
                    ->whereNull('events.deleted_at');
            })
            ->where('venues.status', 'active')
            ->groupBy('venues.id', 'venues.name', 'venues.slug', 'venues.city', 'venues.updated_at')
            ->orderByRaw('COUNT(DISTINCT events.id) DESC')
            ->orderBy('venues.name')
            ->get([
                'venues.name as name',
                'venues.slug as slug',
                'venues.city as city',
                DB::raw('COUNT(DISTINCT events.id) as event_count'),
                DB::raw('GREATEST(venues.updated_at, COALESCE(MAX(events.updated_at), venues.updated_at)) as last_change'),
            ]);

        return array_map(static fn ($row): array => [
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'city' => $row->city === null ? null : (string) $row->city,
            'count' => (int) $row->event_count,
            'last_change' => $row->last_change === null ? null : (string) $row->last_change,
        ], $rows->all());
    }

    /**
     * Публично видимые события площадки, страница `$page`.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function eventsAtVenue(int $venueId, int $page): LengthAwarePaginator
    {
        return $this->paginate(
            $this->publicEvents()->whereExists(function (QueryBuilder $query) use ($venueId): void {
                $query->select(DB::raw(1))
                    ->from('sessions')
                    ->whereColumn('sessions.event_id', 'events.id')
                    ->where('sessions.venue_id', $venueId);
            }),
            $page,
            $venueId,
        );
    }

    /**
     * Публично видимые события города (по всем написаниям), страница `$page`.
     *
     * `$categoryId` добавляет фасет «город × категория» (`/city/{slug}/{category}`).
     * Отдельным параметром, а не отдельным методом: фильтр по городу остаётся
     * тем же самым, и его копия во втором методе разошлась бы при первой правке
     * условия публичности или списка написаний.
     *
     * @param  list<string>  $cityNames
     * @return LengthAwarePaginator<int, Event>
     */
    public function eventsInCity(array $cityNames, int $page, ?int $categoryId = null): LengthAwarePaginator
    {
        $query = $this->publicEvents()->whereExists(function (QueryBuilder $q) use ($cityNames): void {
            $q->select(DB::raw(1))
                ->from('sessions')
                ->join('venues', 'venues.id', '=', 'sessions.venue_id')
                ->whereColumn('sessions.event_id', 'events.id')
                ->whereIn('venues.city', $cityNames);
        });

        if ($categoryId !== null) {
            $query->where('events.category_id', $categoryId);
        }

        return $this->paginate($query, $page, null);
    }

    /**
     * Публично видимые события категории, страница `$page`.
     *
     * СЕАНСЫ НЕ ТРЕБУЮТСЯ, в отличие от страниц города и площадки, и это не
     * недосмотр. Страница места отвечает на вопрос «что здесь идёт» — событие
     * без сеанса там нечего показать. Категория — это фасет КАТАЛОГА, а каталог
     * (`GET /api/v1/events`) фильтруется только по статусу. Требование сеанса
     * здесь сделало бы набор событий на странице категории уже, чем в каталоге,
     * то есть расхождение между двумя представлениями одних данных.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function eventsInCategory(int $categoryId, int $page): LengthAwarePaginator
    {
        return $this->paginate(
            $this->publicEvents()->where('events.category_id', $categoryId),
            $page,
            null,
        );
    }

    /**
     * Публично видимые события организатора, страница `$page`.
     *
     * СЕАНСЫ НЕ ТРЕБУЮТСЯ — по тому же правилу, что у категории, и это не
     * совпадение. Организатор, как и категория, — фасет КАТАЛОГА: публичный
     * `GET /api/v1/events` умеет фильтровать по `organization_id`
     * (`EventRepository::applyFilters()`), причём фильтрует только по статусу.
     * Требование сеанса сделало бы страницу организатора уже каталога, то есть
     * два представления одних данных разошлись бы. Площадка и город, наоборот,
     * отвечают на вопрос «что здесь идёт», и событие без сеанса там показать
     * нечего.
     *
     * Третий аргумент `paginate()` — `null`, а не id площадки: у организатора
     * может быть несколько площадок в разных городах, и сужать выражение
     * ближайшего сеанса до одной из них значило бы показывать дату одного зала
     * для события, которое идёт в другом.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function eventsAtOrganization(int $organizationId, int $page): LengthAwarePaginator
    {
        return $this->paginate(
            $this->publicEvents()->where('events.organization_id', $organizationId),
            $page,
            null,
        );
    }

    /**
     * Публично видимые события артиста, страница `$page` (стратегия SEO, B-1).
     *
     * АРТИСТ — ФАСЕТ КАТАЛОГА, А НЕ МЕСТО, и отсюда всё остальное — ровно как у
     * категории и организатора. Сеанс не требуется, события собираются со всех
     * площадок и городов: страница отвечает на вопрос «где и когда играет этот
     * артист», и ответ на него не зависит от зала. Публичный каталог
     * (`GET /api/v1/events`) фильтруется по статусу так же, поэтому оба
     * представления совпадают. Требование сеанса сделало бы страницу артиста
     * уже каталога — то есть расхождение между двумя представлениями одних
     * данных.
     *
     * `whereExists`, А НЕ `join`. Связь «артист — событие» лежит в
     * `artist_event`, и join дал бы по строке на ребро; уникальность
     * (`uq_artist_event`) сегодня гарантирует одну строку, но `whereExists`
     * выражает вопрос «есть ли у события этот артист» напрямую и не зависит от
     * того, что ребро одно. Тем же способом отобран `eventsAtVenue()`.
     *
     * Третий аргумент `paginate()` — `null`, как у организатора: у артиста
     * события могут идти в разных городах, и сужать выражение ближайшего сеанса
     * до одной площадки значило бы показать дату одного зала для события,
     * которое играется в другом.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function eventsAtArtist(int $artistId, int $page): LengthAwarePaginator
    {
        return $this->paginate(
            $this->publicEvents()->whereExists(function (QueryBuilder $query) use ($artistId): void {
                $query->select(DB::raw(1))
                    ->from('artist_event')
                    ->whereColumn('artist_event.event_id', 'events.id')
                    ->where('artist_event.artist_id', $artistId);
            }),
            $page,
            null,
        );
    }

    /**
     * Ближайший сеанс события: первый будущий, а если таких нет — последний
     * прошедший.
     *
     * ТО ЖЕ ПРАВИЛО, ЧТО В СОРТИРОВКЕ (`nearestAtExpression()`). Дата в списке и
     * порядок списка обязаны совпадать: если бы отображался один сеанс, а
     * сортировка шла по другому, первая же страница выглядела бы
     * неотсортированной. Реализация совпадает и с
     * `EventPageController::nearestSession()` — там то же правило применяется к
     * одному событию на странице события.
     *
     * Прошедшие события попадают в список намеренно: это накопленный контент
     * площадки, по которому приходят запросы вида «что было в Кремле», и в
     * sitemap такие страницы остаются (`completed` входит в `publiclyVisible()`).
     */
    public function nearestSession(Event $event): ?Session
    {
        $sessions = $event->sessions->sortBy('starts_at')->values();

        if ($sessions->isEmpty()) {
            return null;
        }

        $now = now();

        foreach ($sessions as $session) {
            if ($session->starts_at !== null && $session->starts_at >= $now) {
                return $session;
            }
        }

        /** @var Session|null $last */
        $last = $sessions->last();

        return $last;
    }

    /**
     * Выражение SQL: ближайший ПРЕДСТОЯЩИЙ сеанс, либо NULL.
     *
     * ЗАЧЕМ ЭТО В SQL. Список страничный, поэтому порядок обязан задаваться в
     * запросе: сортировка в PHP после `paginate()` упорядочила бы только
     * двадцать записей текущей страницы, а не всю выборку, — то есть
     * «ближайшие события» на второй странице оказались бы произвольными.
     *
     * ПОЧЕМУ ДВА ВЫРАЖЕНИЯ, А НЕ ОДНО С `COALESCE`. Здесь стоял ровно такой
     * `COALESCE(MIN(будущий), MAX(любой))`, и порядок был НЕВЕРНЫМ: у события с
     * единственным прошедшим сеансом `COALESCE` тоже возвращал дату, поэтому
     * признак «будущего сеанса нет» не срабатывал никогда, группы не
     * разделялись, и всё сортировалось по одной дате — прошедшие концерты
     * оказывались выше предстоящих. Разделять группы можно только по
     * НАЛИЧИЮ будущего сеанса, а не по значению ближайшей даты.
     *
     * ПРАВИЛО ТО ЖЕ, ЧТО В `nearestSession()`: сначала предстоящие от ближнего к
     * дальнему, затем прошедшие от недавнего к давнему. Дата в списке и порядок
     * списка обязаны совпадать, иначе первая же страница выглядит
     * неотсортированной.
     *
     * `$venueId` подставляется строкой, но он объявлен `?int`: значение не может
     * быть ничем, кроме числа, поэтому инъекция здесь невозможна. Связать его
     * параметром нельзя без того, чтобы каждый повтор выражения получил свою
     * пару `?`, а порядок склейки привязок в `select` и `order` различается — то
     * есть связанный вариант был бы хрупче явного.
     */
    private function upcomingExpression(?int $venueId): string
    {
        return '(SELECT MIN(s.starts_at) FROM sessions s'
            . ' WHERE s.event_id = events.id' . $this->venueScope($venueId) . ' AND s.starts_at >= ?)';
    }

    /** Выражение SQL: последний сеанс события, независимо от даты. */
    private function pastExpression(?int $venueId): string
    {
        return '(SELECT MAX(s.starts_at) FROM sessions s'
            . ' WHERE s.event_id = events.id' . $this->venueScope($venueId) . ')';
    }

    private function venueScope(?int $venueId): string
    {
        return $venueId === null ? '' : ' AND s.venue_id = ' . $venueId;
    }

    /** События, которые публика видит: те же статусы, что у публичного API. */
    private function publicEvents(): Builder
    {
        return Event::query()->whereIn('events.status', EventStatus::publiclyVisible());
    }

    /**
     * @param  Builder<Event>  $query
     * @return LengthAwarePaginator<int, Event>
     */
    private function paginate(Builder $query, int $page, ?int $venueId): LengthAwarePaginator
    {
        $now = now()->toDateTimeString();
        $upcoming = $this->upcomingExpression($venueId);

        return $query
            ->select('events.*')
            ->selectRaw(self::MIN_PRICE_SQL . ' AS min_price', ['available'])
            // Сеансы нужны только для показа даты и площадки — инвентарь для
            // цены приходит подзапросом, поэтому тяжёлая вложенная предзагрузка
            // здесь не нужна.
            ->with(['sessions' => function ($relation) use ($venueId): void {
                $relation->select('id', 'event_id', 'venue_id', 'starts_at');

                if ($venueId !== null) {
                    $relation->where('venue_id', $venueId);
                }

                $relation->with('venue:id,name,slug');
            }])
            // 1. Сначала события, у которых ЕСТЬ предстоящий сеанс (`IS NULL` = 0
            //    для них и 1 для оставшихся). Именно по наличию будущего сеанса,
            //    а не по значению ближайшей даты: у прошедшего события дата тоже
            //    есть, и по ней группы не разделяются.
            ->orderByRaw('(' . $upcoming . ') IS NULL ASC', [$now])
            // 2. Предстоящие — от ближайшего к дальнему.
            ->orderByRaw($upcoming . ' ASC', [$now])
            // 3. Прошедшие — от недавнего к давнему (внутри второй группы ключ
            //    выше у всех NULL, поэтому порядок здесь и решает).
            ->orderByRaw($this->pastExpression($venueId) . ' DESC')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

}
