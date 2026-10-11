<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Illuminate\Support\Collection;
use Nabilet\Core\Support\Money;
use Nabilet\Core\Support\RuText;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Models\EventCategory;
use Nabilet\Modules\Seo\Domain\LandingPage;
use Nabilet\Modules\Venues\Models\Venue;

/**
 * Сборка посадочной страницы: площадка, город, категория, фасет «город ×
 * категория» и организатор (аудит P-12).
 *
 * ЗАЧЕМ ОТДЕЛЬНО ОТ `CatalogService`. Тот отвечает на вопрос «что в базе», этот
 * — «что написано на странице»: заголовок, описание, вводный текст, порядок и
 * подписи. Разделение не формальное: заголовки и мета-описания — это то, что
 * видит поисковик, и их правят по SEO-причинам, тогда как запросы меняют по
 * причинам производительности. Смешав их, любая правка формулировки трогала бы
 * SQL.
 *
 * ЗДЕСЬ ЖЕ СОБИРАЕТСЯ РАЗМЕТКА СУЩНОСТИ — `Place` для площадки и `Organization`
 * для организатора. Не в рендерере: разметка страницы — это то же самое, что её
 * заголовок и описание, то есть «что мы утверждаем об этой странице», и живёт
 * она там, где утверждения формулируются. Рендерер остаётся шаблоном, который
 * умеет только вставить готовый блок.
 *
 * ВСЁ, ЧТО ЗДЕСЬ СОБИРАЕТСЯ, УНИКАЛЬНО НА СТРАНИЦУ. Название города и площадки
 * в заголовке и в описании — не украшение: посадочные страницы одного шаблона,
 * отличающиеся только списком ссылок, поисковик считает дублями и оставляет в
 * индексе одну из них. Именно поэтому `title`, `description` и `intro` включают
 * название коллекции, а для страниц пагинации — ещё и номер.
 *
 * ПАДЕЖЕЙ ЗДЕСЬ НЕТ НАМЕРЕННО. Русский требует «в Москве», «в Казани»,
 * «в Тюмени» — предложный падеж, который для произвольного названия города
 * вывести нельзя: исключений в правиле больше, чем правил, и ошибка («в Москва»)
 * видна сразу. Поэтому фразы строятся так, чтобы падеж не требовался:
 * «Афиша города Москва», а не «Афиша в Москве». Формулировка чуть более
 * канцелярская — зато не бывает сломанной ни на одном названии.
 */
class LandingPageService
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly StructuredDataService $structuredData,
    ) {}

    public function forVenue(Venue $venue, int $page): LandingPage
    {
        $page = max(1, $page);

        $events = $this->catalog->eventsAtVenue((int) $venue->id, $page);
        $total = $events->total();

        $baseUrl = route('landing.venue', ['slug' => (string) $venue->slug]);
        $name = (string) $venue->name;
        $city = trim((string) $venue->city);
        $address = trim((string) $venue->address);
        $location = trim($city . ($city !== '' && $address !== '' ? ', ' : '') . $address);

        return new LandingPage(
            heading: $this->withPageSuffix(sprintf('Афиша площадки «%s»', $name), $page),
            title: $this->withPageSuffix(sprintf('Афиша площадки «%s» — билеты и расписание', $name), $page),
            description: $this->withPageSuffix(
                sprintf(
                    'Афиша площадки «%s»%s: %d %s, расписание, цены и покупка билетов онлайн.',
                    $name,
                    $location === '' ? '' : ' (' . $location . ')',
                    $total,
                    RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                ),
                $page,
            ),
            intro: sprintf(
                'В афише площадки «%s»%s — %d %s: расписание, цены и покупка билетов онлайн.%s',
                $name,
                $location === '' ? '' : ' (' . $location . ')',
                $total,
                RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                $page > 1 ? sprintf(' Страница %d.', $page) : '',
            ),
            baseUrl: $baseUrl,
            breadcrumbs: $this->crumbs(
                sprintf('Афиша площадки «%s»', $name),
                $baseUrl,
                $page,
            ),
            items: $this->items($events->getCollection()),
            related: $this->relatedVenues($venue),
            // Разметка самого места (аудит P-12). Не то же самое, что `location`
            // внутри разметки события: там место описано В КОНТЕКСТЕ события, а
            // здесь страница сама посвящена площадке, и поисковику нужно сказать
            // именно это. `url` — адрес коллекции без `?page`: разметка
            // описывает сущность, а не её вторую страницу.
            entity: $this->structuredData->generatePlace([
                'name' => $name,
                'url' => $baseUrl,
                'address' => (string) $venue->address,
                'city' => (string) $venue->city,
                'country' => (string) $venue->country,
            ]),
            page: $page,
            lastPage: max(1, $events->lastPage()),
        );
    }

    /**
     * @param  array{name: string, slug: string, names: list<string>, count: int}  $city
     */
    public function forCity(array $city, int $page): LandingPage
    {
        $page = max(1, $page);

        $events = $this->catalog->eventsInCity($city['names'], $page);
        $total = $events->total();

        $baseUrl = route('landing.city', ['slug' => $city['slug']]);
        $name = $city['name'];

        return new LandingPage(
            heading: $this->withPageSuffix(sprintf('Афиша города %s', $name), $page),
            title: $this->withPageSuffix(sprintf('Афиша города %s — билеты и расписание', $name), $page),
            description: $this->withPageSuffix(
                sprintf(
                    'Афиша города %s: %d %s, расписание, цены и покупка билетов онлайн.',
                    $name,
                    $total,
                    RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                ),
                $page,
            ),
            intro: sprintf(
                'В афише города %s — %d %s: расписание, цены и покупка билетов онлайн.%s',
                $name,
                $total,
                RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                $page > 1 ? sprintf(' Страница %d.', $page) : '',
            ),
            baseUrl: $baseUrl,
            breadcrumbs: $this->crumbs(sprintf('Афиша города %s', $name), $baseUrl, $page),
            items: $this->items($events->getCollection()),
            related: $this->relatedCities($city['slug']),
            page: $page,
            lastPage: max(1, $events->lastPage()),
        );
    }

    /**
     * Страница категории — `/category/{slug}` (аудит P-12).
     */
    public function forCategory(EventCategory $category, int $page): LandingPage
    {
        $page = max(1, $page);

        $events = $this->catalog->eventsInCategory((int) $category->id, $page);
        $total = $events->total();

        $baseUrl = route('landing.category', ['slug' => (string) $category->slug]);
        $label = sprintf('Афиша категории «%s»', (string) $category->name);

        return new LandingPage(
            heading: $this->withPageSuffix($label, $page),
            title: $this->withPageSuffix(sprintf('%s — билеты и расписание', $label), $page),
            description: $this->withPageSuffix(
                sprintf(
                    '%s: %d %s, расписание, цены и покупка билетов онлайн.',
                    $label,
                    $total,
                    RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                ),
                $page,
            ),
            intro: sprintf(
                'В категории «%s» — %d %s: расписание, цены и покупка билетов онлайн.%s',
                (string) $category->name,
                $total,
                RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                $page > 1 ? sprintf(' Страница %d.', $page) : '',
            ),
            baseUrl: $baseUrl,
            breadcrumbs: $this->crumbs($label, $baseUrl, $page),
            items: $this->items($events->getCollection()),
            related: $this->relatedCategories((string) $category->slug),
            page: $page,
            lastPage: max(1, $events->lastPage()),
        );
    }

    /**
     * Фасет «город × категория» — `/city/{slug}/{category}` (аудит P-12).
     *
     * ЭТО САМАЯ ЦЕННАЯ ИЗ ПОСАДОЧНЫХ СТРАНИЦ: «концерты в Москве», «театр в
     * Казани» — именно в такой форме формулируется спрос, и до неё у сайта не
     * было адреса под этот запрос вообще.
     *
     * АДРЕС ВЛОЖЕН В `/city/…`, А НЕ ВЫНЕСЕН В КОРЕНЬ (`/{city}/{category}`,
     * как в §2.4 аудита). Отклонение осознанное: маршрут из двух сегментов в
     * корне — это фактически маска, которая перехватывает ЛЮБОЙ двухсегментный
     * путь. Сегодня она ничего не ломает, но она гарантированно проглотит
     * первый же будущий маршрут вида `/payment/{id}` или `/install/{step}`,
     * причём молча: страница просто начнёт отвечать 404, а причина будет не в
     * ней. Проект уже платил за это — маска `{any}` в web-группе перехватила бы
     * весь `/api/v1` (см. комментарий у `Route::fallback()`). Короткий адрес
     * можно добавить позже отдельным решением, вместе с проверкой
     * затенения маршрутов в `tools/verify-route-ownership.php`.
     *
     * @param  array{name: string, slug: string, names: list<string>, count: int}  $city
     */
    public function forCityCategory(array $city, EventCategory $category, int $page): LandingPage
    {
        $page = max(1, $page);

        $events = $this->catalog->eventsInCity($city['names'], $page, (int) $category->id);
        $total = $events->total();

        $baseUrl = route('landing.city.category', [
            'slug' => $city['slug'],
            'category' => (string) $category->slug,
        ]);

        $label = sprintf('Категория «%s» — афиша города %s', (string) $category->name, $city['name']);

        return new LandingPage(
            heading: $this->withPageSuffix($label, $page),
            title: $this->withPageSuffix(sprintf('%s: билеты и расписание', $label), $page),
            description: $this->withPageSuffix(
                sprintf(
                    '%s: %d %s, расписание, цены и покупка билетов онлайн.',
                    $label,
                    $total,
                    RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                ),
                $page,
            ),
            intro: sprintf(
                'Категория «%s» в городе %s — %d %s: расписание, цены и покупка билетов онлайн.%s',
                (string) $category->name,
                $city['name'],
                $total,
                RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                $page > 1 ? sprintf(' Страница %d.', $page) : '',
            ),
            baseUrl: $baseUrl,
            // Средний уровень — РЕАЛЬНАЯ страница города, а не подпись: `item`
            // крошки Google обходит, и ссылка на несуществующий адрес помечается
            // как «URL not found» и обесценивает разметку.
            breadcrumbs: $this->crumbs(
                sprintf('Категория «%s»', (string) $category->name),
                $baseUrl,
                $page,
                [
                    'name' => sprintf('Афиша города %s', $city['name']),
                    'url' => route('landing.city', ['slug' => $city['slug']]),
                ],
            ),
            items: $this->items($events->getCollection()),
            related: $this->relatedCategoriesInCity($city, (string) $category->slug),
            page: $page,
            lastPage: max(1, $events->lastPage()),
        );
    }

    /**
     * Страница организатора — `/organizer/{slug}` (аудит P-12).
     *
     * ОРГАНИЗАТОР — ФАСЕТ КАТАЛОГА, А НЕ МЕСТО, и отсюда всё остальное. Сеанс не
     * требуется, события собираются со всех площадок и городов: страница
     * отвечает на вопрос «что проводит этот организатор», и ответ на него не
     * зависит от того, в каком зале играется событие. Публичный каталог
     * (`GET /api/v1/events`) фильтруется по `organization_id` ровно так же —
     * только по статусу, — поэтому оба представления совпадают. Подробнее —
     * в `CatalogService::eventsAtOrganization()`.
     *
     * ПОДПИСЬ БЕЗ ПАДЕЖА — то же правило, что у города и площадки: «Афиша
     * организатора «Сургут-Концерт»», а не «Афиша Сургут-Концерта». Название
     * организации — произвольная строка от администратора, и склонять её нельзя
     * ни по какому правилу.
     *
     * В ОТЛИЧИЕ ОТ ГОРОДА, У ОРГАНИЗАТОРА ЕСТЬ СВОИ ПОЛЯ, и они попадают в
     * разметку: описание, логотип, контакты. Города как сущности в схеме нет
     * вовсе (`venues.city` — свободный текст), поэтому у страницы города
     * разметки сущности не бывает.
     */
    public function forOrganizer(Organization $organization, int $page): LandingPage
    {
        $page = max(1, $page);

        $events = $this->catalog->eventsAtOrganization((int) $organization->id, $page);
        $total = $events->total();

        $baseUrl = route('landing.organizer', ['slug' => (string) $organization->slug]);
        $name = (string) $organization->name;
        $label = sprintf('Афиша организатора «%s»', $name);

        return new LandingPage(
            heading: $this->withPageSuffix($label, $page),
            title: $this->withPageSuffix(sprintf('%s — билеты и расписание', $label), $page),
            description: $this->withPageSuffix(
                sprintf(
                    '%s: %d %s, расписание, цены и покупка билетов онлайн.',
                    $label,
                    $total,
                    RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                ),
                $page,
            ),
            intro: sprintf(
                'В афише организатора «%s» — %d %s: расписание, цены и покупка билетов онлайн.%s',
                $name,
                $total,
                RuText::plural($total, 'мероприятие', 'мероприятия', 'мероприятий'),
                $page > 1 ? sprintf(' Страница %d.', $page) : '',
            ),
            baseUrl: $baseUrl,
            breadcrumbs: $this->crumbs($label, $baseUrl, $page),
            items: $this->items($events->getCollection()),
            related: $this->relatedOrganizations((string) $organization->slug),
            entity: $this->structuredData->generateOrganization([
                'name' => $name,
                'url' => $baseUrl,
                'description' => $organization->description,
                'logo' => $organization->logo,
                'email' => $organization->email,
                'phone' => $organization->phone,
            ]),
            page: $page,
            lastPage: max(1, $events->lastPage()),
        );
    }

    /**
     * Хлебные крошки коллекции.
     *
     * ПОСЛЕДНИЙ УРОВЕНЬ — КАНОНИЧЕСКИЙ АДРЕС СТРАНИЦЫ, и это требование к
     * разметке, а не оформление: Google сверяет последнюю позицию `BreadcrumbList`
     * с `<link rel="canonical">` и при расхождении отбрасывает разметку целиком.
     * На страницах пагинации уровень «Страница N» существует именно поэтому: без
     * него последней крошкой была бы первая страница, и её адрес не совпал бы с
     * canonical второй.
     *
     * Промежуточный уровень на `?page=N` ведёт на ПЕРВУЮ страницу коллекции —
     * реально существующий адрес. Это тоже важно: `item` крошки Google обходит,
     * и битая ссылка в разметке помечается как «URL not found».
     *
     * `$middle` — необязательный уровень между «Главная» и текущим. Нужен
     * странице «город × категория»: её иерархия трёхуровневая, и без среднего
     * уровня категория висела бы прямо под главной, то есть разметка сообщала бы
     * поисковику неверный путь. Уровень передаётся ГОТОВОЙ парой «подпись + url»
     * (а не собирается здесь), потому что адрес города знает вызывающий, и
     * повторять здесь `route('landing.city', …)` означало бы вторую копию
     * формата адреса.
     *
     * @param  array{name: string, url: string}|null  $middle
     * @return list<array{name: string, url: string}>
     */
    private function crumbs(string $label, string $baseUrl, int $page, ?array $middle = null): array
    {
        $canonical = LandingPage::pageUrlFor($baseUrl, $page);

        $trail = [['name' => 'Главная', 'url' => url('/')]];

        if ($middle !== null) {
            $trail[] = $middle;
        }

        $trail[] = ['name' => $label, 'url' => $page > 1 ? $baseUrl : $canonical];

        if ($page > 1) {
            $trail[] = ['name' => sprintf('Страница %d', $page), 'url' => $canonical];
        }

        return $trail;
    }

    private function withPageSuffix(string $text, int $page): string
    {
        return $page > 1 ? sprintf('%s — страница %d', $text, $page) : $text;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return list<array{title: string, url: string, date: ?string, venue: ?string, price: ?string}>
     */
    private function items(Collection $events): array
    {
        $items = [];

        foreach ($events as $event) {
            $session = $this->catalog->nearestSession($event);

            $items[] = [
                'title' => (string) $event->title,
                // Через именованный маршрут, а не склейкой строки: адрес события
                // строится из slug и public_id, и его формат уже менялся один раз
                // (см. `EventPageController` — 301 при чужом public_id). Копия
                // формата здесь разошлась бы с контроллером молча.
                'url' => route('events.show', [
                    'slug' => (string) $event->slug,
                    'publicId' => (string) $event->public_id,
                ]),
                'date' => $session?->starts_at?->toIso8601String(),
                'venue' => $session?->venue?->name === null ? null : (string) $session->venue->name,
                'price' => $this->priceFrom($event->min_price ?? null),
            ];
        }

        return $items;
    }

    /**
     * Цена «от N ₽» — в том же виде, что на витрине.
     *
     * ПОЧЕМУ НЕ `Money::format()`. Он всегда печатает два знака после запятой
     * («2 500,00 ₽»), тогда как витрина показывает целые рубли
     * (`resources/js/lib/format.ts`, `maximumFractionDigits: 0`). Две разные
     * записи одной цены на соседних экранах читаются как разные цены. Копейки
     * показываются только тогда, когда они действительно есть, — и в этом
     * случае формат отдаётся `Money`, чтобы не заводить третью формулу.
     *
     * Деление на 100 — целочисленное (`intdiv`), потому что деньги в проекте
     * дробными не бывают: `2500.00` из `250000` копеек получается точно.
     */
    private function priceFrom(mixed $minorUnits): ?string
    {
        if (! is_numeric($minorUnits)) {
            return null;
        }

        $minor = (int) $minorUnits;

        if ($minor < 0) {
            return null;
        }

        if ($minor % 100 === 0) {
            return 'от ' . number_format(intdiv($minor, 100), 0, ',', ' ') . ' ₽';
        }

        return 'от ' . Money::of($minor, 'RUB')->format();
    }

    /**
     * @return list<array{label: string, url: string, count: int}>
     */
    private function relatedVenues(Venue $venue): array
    {
        $city = trim((string) $venue->city);
        $related = [];

        foreach ($this->catalog->venues() as $candidate) {
            if ($candidate['slug'] === (string) $venue->slug || $candidate['count'] < 1) {
                continue;
            }

            // Соседние площадки — те, что в том же городе. Если город у площадки
            // не заполнен, ограничение снимается: лучше ссылка на площадку из
            // другого города, чем блок перелинковки из одной строки.
            if ($city !== '' && trim((string) $candidate['city']) !== $city) {
                continue;
            }

            $related[] = [
                'label' => $candidate['name'],
                'url' => route('landing.venue', ['slug' => $candidate['slug']]),
                'count' => $candidate['count'],
            ];

            if (count($related) === CatalogService::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
    }

    /**
     * @return list<array{label: string, url: string, count: int}>
     */
    private function relatedCities(string $slug): array
    {
        $related = [];

        foreach ($this->catalog->cities() as $candidate) {
            if ($candidate['slug'] === $slug || $candidate['count'] < 1) {
                continue;
            }

            $related[] = [
                'label' => $candidate['name'],
                'url' => route('landing.city', ['slug' => $candidate['slug']]),
                'count' => $candidate['count'],
            ];

            if (count($related) === CatalogService::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
    }

    /**
     * Соседние категории страницы категории — другие категории каталога.
     *
     * Число у ссылки — ОБЩЕЕ число событий категории, и это честно: ссылка ведёт
     * на страницу категории, а она показывает события всех городов.
     *
     * @return list<array{label: string, url: string, count: int}>
     */
    private function relatedCategories(string $slug): array
    {
        $related = [];

        foreach ($this->catalog->categories() as $candidate) {
            if ($candidate['slug'] === $slug || $candidate['count'] < 1) {
                continue;
            }

            $related[] = [
                'label' => $candidate['name'],
                'url' => route('landing.category', ['slug' => $candidate['slug']]),
                'count' => $candidate['count'],
            ];

            if (count($related) === CatalogService::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
    }

    /**
     * Соседние категории страницы «город × категория» — другие категории ТОГО ЖЕ
     * города, и число у ссылки тоже городское.
     *
     * ПОЧЕМУ ГОРОДСКОЕ ЧИСЛО. Ссылка ведёт на `/city/{slug}/{category}`, то есть
     * на список событий этого города в этой категории. Показать рядом
     * общероссийское число значило бы пообещать читателю больше, чем он найдёт по
     * ссылке, — а первое, что он сделает, это сверит число с содержимым.
     *
     * @param  array{name: string, slug: string, names: list<string>, count: int}  $city
     * @return list<array{label: string, url: string, count: int}>
     */
    private function relatedCategoriesInCity(array $city, string $categorySlug): array
    {
        $related = [];

        foreach ($this->catalog->categoriesInCity($city['names']) as $candidate) {
            if ($candidate['slug'] === $categorySlug || $candidate['count'] < 1) {
                continue;
            }

            $related[] = [
                'label' => $candidate['name'],
                'url' => route('landing.city.category', [
                    'slug' => $city['slug'],
                    'category' => $candidate['slug'],
                ]),
                'count' => $candidate['count'],
            ];

            if (count($related) === CatalogService::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
    }

    /**
     * Соседние организаторы страницы организатора — другие организаторы каталога.
     *
     * Число у ссылки — ОБЩЕЕ число событий организатора, и это честно: ссылка
     * ведёт на его страницу, а она собирает события всех его площадок и городов.
     * Городского варианта этого числа здесь нет и быть не может — страницы
     * «организатор в городе» в проекте не существует, и ссылаться на неё было бы
     * обещанием, которого адрес не выполняет.
     *
     * @return list<array{label: string, url: string, count: int}>
     */
    private function relatedOrganizations(string $slug): array
    {
        $related = [];

        foreach ($this->catalog->organizations() as $candidate) {
            if ($candidate['slug'] === $slug || $candidate['count'] < 1) {
                continue;
            }

            $related[] = [
                'label' => $candidate['name'],
                'url' => route('landing.organizer', ['slug' => $candidate['slug']]),
                'count' => $candidate['count'],
            ];

            if (count($related) === CatalogService::RELATED_LIMIT) {
                break;
            }
        }

        return $related;
    }
}
