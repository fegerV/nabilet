<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Nabilet\Core\Http\NotFoundPage;
use Nabilet\Core\Support\AssetUrl;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Events\Domain\EventRobots;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Http\EventPageShell;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Repositories\EventRepository;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Seo\Services\StructuredDataService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Серверная обёртка страницы мероприятия для SEO.
 *
 * Поисковые боты не исполняют JavaScript SPA-приложения (или исполняют
 * медленно), поэтому страница события отдаётся как готовый HTML с:
 *   — уникальными <title>, meta description, canonical, og-тегами;
 *   — JSON-LD разметкой schema.org/Event (StructuredDataService);
 *   — телом с контейнером #app, в который Vue монтирует интерактив.
 *
 * Данные всегда из БД по slug — пересборка фронта при новом зале/концерте
 * не нужна: изменяется только запись в таблице events.
 */
class EventPageController
{
    /**
     * Заглушка `og:image` для события без постера.
     *
     * РАСТРОВАЯ, А НЕ SVG. Раньше здесь стоял `og-default.svg`, и это ломало
     * превью ровно там, где оно важнее всего: парсеры VK и iMessage не читают
     * SVG и отбрасывают тег, поэтому карточка ссылки у события без постера
     * раскрывалась без картинки. Аудитория проекта распространяет афиши именно
     * через эти мессенджеры. PNG генерируется скриптом `tools/gen-og-default.php`
     * (он же документирует состав картинки и её фирменные цвета).
     */
    private const OG_FALLBACK = 'images/og-default.png';

    /** Размеры заглушки — известны, потому что мы её и рисуем. */
    private const OG_FALLBACK_WIDTH = 1200;
    private const OG_FALLBACK_HEIGHT = 630;

    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepository $events,
        private readonly StructuredDataService $structuredData,
        private readonly EventPageShell $shell
    ) {}

    /**
     * GET /event/{slug}/{publicId}
     * GET /event/{slug}            → редирект на полный URL (canonical).
     */
    public function show(string $slug, ?string $publicId = null): Response | JsonResponse | RedirectResponse
    {
        $event = $this->events->findBySlug($slug);

        if ($event === null) {
            // УДАЛЁННОЕ событие — 410 Gone, а не 404 (аудит P-24).
            //
            // Разница существенна именно для поисковика: 404 читается как
            // «проверьте ссылку», страница остаётся в индексе до следующего
            // обхода, и её нужно обойти ещё раз, чтобы понять, что она не
            // вернулась. 410 означает «была и не вернётся» — URL снимается из
            // индекса без повторных обходов. Строка при мягком удалении
            // сохраняется, поэтому ответить 410 мы можем: ресурс существовал.
            if ($this->events->findTrashedBySlug($slug) !== null) {
                return $this->gone($slug);
            }

            return $this->notFound($slug);
        }

        // КАКОЙ СТАТУС ИМЕЕТ ПРАВО НА СТРАНИЦУ
        //
        // Здесь стояло `status === 'draft' || status === 'archived'` → 404. Для
        // `draft` это верно, для `archived` — нет, и это была самая дорогая
        // ошибка в SEO-поверхности: `archived` — ТЕРМИНАЛЬНОЕ состояние
        // (`EventStateMachine`), архивация необратима, а страница с накопленным
        // трафиком, историей и внешними ссылками исчезала мгновенно и
        // безвозвратно. Робот, пришедший по проиндексированному адресу, получал
        // жёсткий 404, и восстановить позиции было нечем: менеджера редиректов в
        // коде не существовало (теперь существует — `Seo\Services\RedirectResolver`
        // перехватывает такие адреса РАНЬШЕ этого контроллера).
        //
        // Теперь модель одна: страницу получает ВСЁ, что уже выходило на сайт, а
        // индексируемость регулируется `publiclyVisible()` в sitemap и `noindex`
        // здесь (`defaultRobots()`). 404 остаётся только за черновиком — тем, что
        // публика не видела никогда.
        if ($event->status === EventStatus::DRAFT) {
            return $this->notFound($slug);
        }

        // Кеш собранной SEO-страницы (аудит производительности 1.1).
        //
        // КЕШИРУЕМ ТОЛЬКО published: archived/completed/cancelled редко
        // запрашиваются, а отмена события должна снять «билеты в продаже»
        // мгновенно, без окна устаревания.
        //
        // КЛЮЧ включает отпечаток сеансов (количество + максимальный
        // updated_at): добавление сеанса или правка цен меняет JSON-LD
        // (offers/startDate) — собранная страница перестаёт быть валидной,
        // хотя сама строка events не менялась и её updated_at не сдвинулся.
        // Правка события меняет `events.updated_at` — ключ уезжает
        // сам, без ручной инвалидации (TTL-нижний предел — 5 минут).
        if ($event->status === EventStatus::PUBLISHED) {
            $fingerprint = \Illuminate\Support\Facades\DB::table('sessions')
                ->where('event_id', $event->id)
                ->selectRaw('COUNT(*) as session_count, MAX(updated_at) as last_update')
                ->first();

            $cacheKey = sprintf(
                'event-page:%d:%s:%d:%s',
                $event->id,
                (string) $event->updated_at?->format('YmdHis'),
                (int) ($fingerprint->session_count ?? 0),
                (string) $fingerprint->last_update,
            );

            $cachedHtml = Cache::get($cacheKey);

            if (is_string($cachedHtml) && $cachedHtml !== '') {
                return response($cachedHtml)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }
        }

        // Канонический URL: /event/{slug}/{publicId}.
        //
        // `events.canonical_url` побеждает вычисленный адрес, но только если он
        // ведёт на НАШ домен (см. `resolveCanonical()`): раньше значение из БД
        // принималось как есть, и одна опечатка в админке снимала страницу с
        // индексации («alternate page with proper canonical tag»).
        $canonical = $this->resolveCanonical($event);

        // 301 и когда publicId не указан вовсе, и когда он ЧУЖОЙ.
        //
        // Раньше проверялось только наличие сегмента: `/event/slug/AAA111` и
        // `/event/slug/BBB222` отдавали 200 с одним и тем же содержимым и одним
        // canonical — то есть число дублей было неограниченным, и краулинговый
        // бюджет уходил на адреса, которых не существует. Правильная реакция на
        // несовпадение — не 404 (адрес всё-таки наш), а 301 на канонический.
        if ($publicId === null || $publicId !== $event->public_id) {
            return response()->redirectTo($canonical, 301);
        }

        // Страница события собирается ИЗ собранной витрины. Если `dist/` нет,
        // раньше отдавался 200 с пустым `<div id="app">` и шаблонным title — то
        // есть soft 404 / «тонкая страница» под живым адресом из sitemap, и
        // поисковик наполнял индекс пустыми дублями. Главная в этом состоянии
        // давно отдаёт честный 503; здесь теперь так же.
        if (! file_exists($this->shell->path())) {
            return $this->storefrontNotBuilt();
        }

        // `sessions.venue` тянем сразу: и JSON-LD, и (ниже) дата начала события
        // берутся из ближайшего сеанса. Без `venue` в цепочке `$session->venue`
        // даёт N+1 запрос на каждое место, а мест в зале сотни.
        //
        // Раньше здесь грузился ЦЕЛИКОМ `sessions.inventoryItems` — все строки
        // инвентаря всех сеансов события (аудит 1.2): offers нужны только три
        // числа ближайшего сеанса (мин. цена, кол-во доступных, валюта), и они
        // считаются одним запросом в `offerPayload()`.
        $event->load(['category', 'translations', 'sessions.venue', 'organization', 'artists']);

        // Дата события живёт НЕ в `events` — в таблице нет ни `start_datetime`,
        // ни `start_date`, есть только `published_at` (момент выхода на сайт).
        // Дата концерта хранится в `sessions.starts_at` (NOT NULL), потому что
        // у одного события может быть несколько сеансов.
        //
        // Раньше здесь передавался `$event->start_datetime` — свойства нет ни в
        // модели, ни в таблице, Eloquent возвращал null, и наружу уходил
        // `"startDate": null`. Google такой JSON-LD отвергает целиком, то есть
        // rich-сниппет не появлялся вообще — а это и был весь смысл разметки.
        //
        // Считается ДО title/description: и то, и другое для прошедшего события
        // называет дату, а для отменённого — не называет вовсе.
        $nearestSession = $this->nearestSession($event);

        // Заголовок и описание зависят от СТАТУСА (аудит P-05/P-17). Раньше
        // прошедшее и отменённое событие отдавали то же «… — купить билеты», что
        // и живое: сниппет в выдаче обещал покупку, которой на странице нет.
        $title = $this->buildTitle($event);
        $description = $this->buildDescription($event, $nearestSession);

        // Цены/наличие для `offers`. Считается один раз: и `price`, и
        // `availability` берутся из одного и того же набора позиций инвентаря.
        $offer = $this->offerPayload($event, $nearestSession);

        $jsonLd = json_encode($this->structuredData->generateEventData([
            'title'               => $event->title,
            'short_description'   => $event->short_description,
            'description'         => $event->description,
            'slug'                => $event->slug,
            'public_id'           => $event->public_id,
            'status'              => $event->status,
            'poster'              => $event->poster,
            'cover'               => $event->cover,
            'start_datetime'      => $nearestSession?->starts_at?->toIso8601String(),
            'end_datetime'        => $nearestSession?->ends_at?->toIso8601String(),
            'canonical_url'       => $canonical,
            // Площадка известна — она лежит в сеансе. Пустая строка здесь
            // означала, что в `location` уезжал `name: ''`, и Google считал
            // разметку неполной: для Event `location` — обязательное поле.
            'venue'               => $this->venuePayload($event, $nearestSession),
            // `toArray()` модели организации вываливал в публичный HTML ВСЮ
            // строку: e-mail, телефон, `settings_json`, `status`, timestamps.
            // В разметке нужны ровно два поля.
            'organization'        => [
                'name' => $event->organization?->name ?? '',
                'url'  => config('app.url'),
            ],
            // Цена и наличие — из РЕАЛЬНОГО инвентаря сеанса.
            //
            // До этого ключи не передавались вовсе, и `buildOffers()` отдавал
            // fallback: `price: 0`, `validFrom: null`, `name: "General
            // Admission"`. Google читал это как «бесплатно» — при том что
            // билеты платные, — и ценовой сниппет не появлялся. Разметка,
            // противоречащая странице, хуже отсутствия разметки.
            'ticket_price'        => $offer['price'] ?? null,
            'currency'            => $offer['currency'] ?? null,
            'availability'        => $offer['availability'] ?? null,
            'published_at'        => $nearestSession?->sales_start_at?->toIso8601String(),
        ]));

        // Хлебные крошки (аудит P-09) — ОТДЕЛЬНЫМ блоком JSON-LD, а не внутри
        // Event. Два независимых документа в двух `<script>` — это нормальный
        // и самый совместимый способ: склеивать их в `@graph` пришлось бы
        // ради экономии одного тега, а раздельные блоки читаются и
        // валидатором Google, и Яндекс.Вебмастером по отдельности.
        $breadcrumb = json_encode($this->structuredData->generateBreadcrumbList(
            $this->breadcrumbTrail($event, $canonical)
        ));

        $html = $this->shell->render([
            'title'         => $title,
            'description'   => $description,
            'canonical'     => $canonical,
            // Постер события, а если его нет — фирменная заглушка.
            //
            // Раньше при отсутствии постера ключ не вставлялся вовсе, и карточка
            // ссылки в мессенджере раскрывалась без картинки: `og:image`
            // опционален по спецификации, но без него превью почти всегда
            // пустое, а именно через мессенджеры у «Сургут-Концерта» и идёт
            // распространение афиш. Заглушка лежит в `public/images/`
            // и обслуживается тем же правилом, что и остальные статические файлы.
            //
            // АДРЕС АБСОЛЮТНЫЙ, в отличие от разметки в `index.html`.
            // Спецификация Open Graph требует полный URL: относительный путь
            // Facebook, Telegram и VK разрешают по-разному, а часть парсеров
            // просто отбрасывает тег. Проверено: без схемы превью не строилось.
            'image'         => $event->poster
                ? $this->storageUrl($event->poster)
                : $this->publicUrl(self::OG_FALLBACK),
            // Размеры и alt (аудит P-18). Размеры известны только у заглушки —
            // её рисуем мы. У постера их пришлось бы читать с диска на каждый
            // рендер страницы, а неверное значение хуже отсутствующего: парсер
            // обрежет превью по нему. Поэтому для постера размеры не выводятся.
            'imageAlt'      => $event->poster
                ? sprintf('Афиша мероприятия «%s»', $event->title)
                : 'NABILET — билеты на события',
            'imageWidth'    => $event->poster ? null : self::OG_FALLBACK_WIDTH,
            'imageHeight'   => $event->poster ? null : self::OG_FALLBACK_HEIGHT,
            'jsonLd'        => $jsonLd,
            'jsonLdBreadcrumb' => $breadcrumb,
            // `public_id` события — идентификатор в `dataLayer.push` (B-7):
            // GA4 связывает `event_view` (цель SPA) с серверным `page_view`
            // именно по нему. Строкой, а не числом: ULID base32 не приводится.
            'eventPublicId' => (string) $event->public_id,
            // Серверный текст внутри `#app` (аудит P-05/P-17/P-20).
            //
            // Сначала был только блок статуса, и это решало половину задачи:
            // краулер без JS видел, что концерт отменён, но у ЖИВОГО события
            // видел пустой контейнер — ни названия, ни даты, ни площадки, ни
            // описания. Теперь отдаётся и контентный блок (см. `contentFallback`).
            'bodyFallback'  => $this->bodyFallback($event, $nearestSession, $offer),
            // `events.robots` до этого не читалось вообще: администратор мог
            // проставить `noindex`, и ничего бы не произошло. Теперь значение
            // управляемо, поэтому оно и разбирается: недопустимые директивы
            // отбрасываются, а не уезжают в мета-тег как есть (аудит P-23).
            //
            // `index` НЕ МОЖЕТ отменить автоматический `noindex`. Для
            // scheduled/cancelled страница обязана быть закрыта от индекса
            // независимо от того, что написано в поле: это следствие статуса, а
            // не настройка. Раньше здесь стояло `$event->robots ?: …`, и
            // значение `index, follow`, выставленное для отменённого события,
            // открывало его для индексации — то есть поле отключало защиту,
            // вместо того чтобы её дополнять.
            'robots'        => $this->effectiveRobots($event),
        ]);

        // Кладём в кеш только то, что уже кешируется: published (см. выше).
        if ($event->status === EventStatus::PUBLISHED && isset($cacheKey)) {
            Cache::put($cacheKey, $html, now()->addMinutes(5));
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Абсолютный URL файла в `storage/app/public` (то есть `/storage/...`).
     *
     * Отдельный метод от `publicUrl()`, потому что это РАЗНЫЕ корни. Постер
     * события лежит на диске `public` и отдаётся символической ссылкой
     * `public/storage` → `storage/app/public`, поэтому его URL всегда
     * `/storage/{path}`. Заглушка же — обычный статический файл в `public/`,
     * он отдаётся из корня.
     *
     * Раньше обе ветки шли через один метод с зашитым `/storage/`, и
     * `og:image` для события без постера получал
     * `https://…/storage/images/og-default.svg` — 404. Превью в мессенджере
     * не строилось, а робот соцсети кэшировал ошибку на сутки.
     *
     * Сама логика теперь в `AssetUrl`: у этих двух корней появился второй
     * потребитель (письмо с билетом, где нужна афиша), и копия правила — это
     * ровно тот способ, которым ошибка выше и появилась.
     */
    private function storageUrl(string $path): string
    {
        return AssetUrl::storage($path);
    }

    /**
     * Абсолютный URL статического файла из `public/` (favicon, заглушка og).
     *
     * Префикс `/storage/` здесь не добавляется: файл лежит в `public/images`
     * и обслуживается веб-сервером напрямую по `/images/…`.
     */
    private function publicUrl(string $path): string
    {
        return AssetUrl::public($path);
    }

    /**
     * Единый 404 для страницы события.
     *
     * ТЕПЕРЬ ЭТО БРЕНДИРОВАННАЯ HTML-СТРАНИЦА, А НЕ JSON-КОНВЕРТ (аудит P-16).
     *
     * Раньше отдавался конверт §66 — формат, правильный для API и неправильный
     * здесь: `/event/…` это адрес, который человек открывает из поисковой выдачи
     * или из письма. `{"error":{"code":"EVENT_NOT_FOUND"}}` в браузере читается
     * как «сайт сломан», а не как «этой страницы больше нет», и не содержит ни
     * одной ссылки — то есть оставляет пользователя в тупике ровно там, где
     * единственное полезное действие — вернуться в афишу.
     *
     * Конверт §66 при этом никуда не делся: он остаётся у JSON-API
     * (`EventController::showBySlug`), где он и уместен.
     *
     * Ответ одинаков и для «нет такого slug», и для черновика — намеренно:
     * разница между «не существует» и «существует, но скрыто» — это информация
     * для того, кто перебирает адреса.
     */
    private function notFound(string $slug): Response | JsonResponse
    {
        return NotFoundPage::response(
            request()->getPathInfo(),
            sprintf('Мероприятие «%s» не найдено.', $slug),
        );
    }

    /**
     * 410 для удалённого мероприятия (аудит P-24).
     *
     * Тот же брендированный документ, что и 404, но с другим заголовком и другим
     * кодом ответа. Код здесь — не формальность: 404 читается как «проверьте
     * ссылку» и требует повторных обходов, 410 означает «была и не вернётся»,
     * и по нему поисковик снимает URL из индекса сразу. Различать эти состояния
     * нужно и человеку: «мероприятие удалено» избавляет от поиска опечатки в
     * адресе, которой там нет.
     */
    private function gone(string $slug): Response | JsonResponse
    {
        return NotFoundPage::gone(
            request()->getPathInfo(),
            sprintf('Мероприятие «%s» удалено.', $slug),
        );
    }

    /**
     * Канонический адрес страницы — из БД, но только если он ведёт на наш домен.
     *
     * ПОЧЕМУ НУЖНА ПРОВЕРКА. `events.canonical_url` — свободное поле, которое
     * заполняет администратор. Оно уходит в `<link rel="canonical">`, в JSON-LD и
     * в sitemap. Если туда попадает чужой домен (скопированный адрес конкурента,
     * тестовый стенд, опечатка), Google видит «эта страница — копия вон той» и
     * снимает её с индексации целиком. Ошибка стоит всей органики страницы и
     * ничем не сигнализирует о себе — ни 500, ни в логах.
     *
     * Относительный путь принимается: он по определению наш, и это ровно то, что
     * подставляет сам администратор, когда пишет `/event/…`.
     *
     * Абсолютный адрес принимается только при совпадении хоста с `app.url` или с
     * хостом текущего запроса. Второе нужно для стендов и локальной разработки,
     * где `APP_URL` в `.env` может отставать от фактического адреса.
     */
    private function resolveCanonical(Event $event): string
    {
        $computed = route('events.show', [
            'slug' => $event->slug,
            'publicId' => $event->public_id,
        ]);

        $stored = is_string($event->canonical_url) ? trim($event->canonical_url) : '';

        if ($stored === '') {
            return $computed;
        }

        $host = parse_url($stored, PHP_URL_HOST);

        // Относительный путь — наш по определению. Абсолютный URL без хоста
        // (`mailto:`, `javascript:`) сюда не попадёт: у них хост тоже `null`,
        // но и `path` не начинается со слэша.
        if ($host === null) {
            return str_starts_with($stored, '/')
                ? rtrim((string) config('app.url'), '/') . $stored
                : $computed;
        }

        $allowedHosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            request()->getHost(),
        ]);

        return in_array($host, $allowedHosts, true) ? $stored : $computed;
    }

    /**
     * Данные предложения для JSON-LD из реального инвентаря ближайшего сеанса.
     *
     * Минимальная цена среди ДОСТУПНЫХ позиций — то, что видит покупатель на
     * витрине и в фильтре «от N ₽». Максимум здесь не нужен: schema.org `price`
     * в одиночном Offer — это цена входа, а не диапазон.
     *
     * Деньги — целые минорные единицы (`Money`), наружу уходит десятичная строка
     * через `Money::toDecimal()`. Считать цену флоатом нельзя: в проекте это
     * запрещено правилом, и `2500.00` из `250000` копеек получается строго.
     *
     * КУПИТЬ МОЖНО ТОЛЬКО ОПУБЛИКОВАННОЕ СОБЫТИЕ (аудит P-05). Наличие билетов
     * считается по инвентарю, а инвентарь прошедшего события никто не чистит:
     * непроданные места остаются `available`, и в разметку уезжал
     * `availability: InStock` для концерта, который уже состоялся. Google
     * показывал «билеты в наличии» на странице прошедшего мероприятия — то есть
     * разметка обещала покупку, которой нет. Статус здесь важнее инвентаря.
     *
     * ПУСТОЙ РЕЗУЛЬТАТ — ЭТО НОРМА. У события может не быть сеансов (оно не
     * публикуется без них, но страница живёт и для `completed`), а у сеанса —
     * доступных мест (всё распродано). Тогда ключи не передаются, и
     * `buildOffers()` уходит в свой прежний fallback; выдумывать цену нельзя.
     *
     * @return array{price: string, currency: string, availability: string}|array{}
     */
    private function offerPayload(Event $event, ?object $session): array
    {
        // Три числа вместо N строк инвентаря (аудит 1.2): мин. цена, сколько
        // позиций доступно, валюта. Один агрегат по сеансу; строки не грузятся.
        $aggregate = $session === null ? null : \Illuminate\Support\Facades\DB::table('inventory_items')
            ->where('session_id', $session->id)
            ->where('status', 'available')
            ->where('available_quantity', '>', 0)
            ->selectRaw('MIN(price_amount) as min_price, COUNT(*) as available_count, MIN(currency) as currency')
            ->first();

        // Валюта нужна и в ветке «купить нельзя»: `offers.priceCurrency` —
        // обязательное поле, и Offer без него Google отбраковывает.
        $currency = (string) ($aggregate?->currency ?: 'RUB');

        if ($event->status !== EventStatus::PUBLISHED) {
            return [
                'price' => '',
                'currency' => $currency,
                // Отменённое событие — `OutOfStock` («не продаётся»), а не
                // `SoldOut` («распродано»): разница существенна и для покупателя,
                // и для разметки. `getOfferAvailability()` различает их по
                // значению, поэтому литерал статуса передаётся как есть.
                //
                // Всё остальное непубличное (completed, archived, scheduled) —
                // `sold_out`. Для `scheduled` это особенно важно: ветка по
                // умолчанию в `getOfferAvailability()` вернула бы `InStock`, то
                // есть обещание продажи на событие, которое ещё не вышло.
                'availability' => $event->status === EventStatus::CANCELLED ? 'cancelled' : 'sold_out',
            ];
        }

        if ($aggregate === null) {
            return [];
        }

        // Всё раскуплено или продажи не открыты: `SoldOut` — это сигнал, а не
        // отсутствие данных. Google показывает «билетов нет» вместо «бесплатно».
        if ((int) $aggregate->available_count === 0) {
            return [
                'price' => '',
                'currency' => $currency,
                'availability' => 'sold_out',
            ];
        }

        $minPrice = $aggregate->min_price;

        if (! is_numeric($minPrice)) {
            return [];
        }

        return [
            'price' => Money::of((int) $minPrice, $currency)->toDecimal(),
            'currency' => $currency,
            'availability' => 'available',
        ];
    }

    /**
     * 503, когда витрина не собрана.
     *
     * Тот же контракт и тот же код, что у главной страницы (`routes/web.php`):
     * поисковик видит «временно недоступно» и НЕ индексирует пустую страницу,
     * а не «тонкий дубль» под живым адресом из sitemap. `Retry-After` говорит
     * роботу, что состояние временное, и он вернётся.
     */
    private function storefrontNotBuilt(): Response | JsonResponse
    {
        return response()
            ->json([
                'error' => [
                    'code' => 'STORE_FRONT_NOT_BUILT',
                    'message' => 'Витрина не собрана. Запустите `npm run build` (vite.config.ts → dist/).',
                ],
            ], 503)
            ->header('Retry-After', '3600');
    }

    /**
     * `noindex` для статусов, которые вне `publiclyVisible()`.
     *
     * Возвращает `null` для published/completed — они индексируются, и мета-тег
     * им не нужен. `noarchive` добавлен по той же причине, что и в
     * `PreventIndexingOfServicePages`: не держать копию отменённого или
     * отложенного события в кэше поисковика, когда страница уже изменилась.
     */
    private function defaultRobots(Event $event): ?string
    {
        return in_array($event->status, EventStatus::publiclyVisible(), true)
            ? null
            : 'noindex, noarchive';
    }

    /**
     * Итоговое значение `<meta name="robots">` (аудит P-23).
     *
     * Две части складываются, и это не «объединение ради объединения»:
     *
     *   • автоматика по статусу — НЕОТМЕНЯЕМЫЙ минимум. Для `scheduled` и
     *     `cancelled` страница обязана быть закрыта от индекса, чем бы ни было
     *     поле: в sitemap её нет, а её сниппет обещал бы покупку, которой не
     *     будет. Значение `index` из поля эту защиту отключить не может;
     *   • поле администратора — дополнение. Для published/completed автоматика
     *     молчит, поэтому поле работает как есть: `noindex` для отдельного
     *     события, `nosnippet` для страницы с чужими фото и так далее.
     *
     * Недопустимые токены отбрасываются (`EventRobots::parse()`), а
     * взаимоисключающие — схлопываются в запрещающую: `index, noindex` в одном
     * теге разные роботы разрешают по-разному, и полагаться на это нельзя.
     */
    private function effectiveRobots(Event $event): ?string
    {
        $required = $this->defaultRobots($event);
        $custom = EventRobots::parse((string) $event->robots);

        if ($required === null) {
            return EventRobots::normalize($custom);
        }

        return EventRobots::normalize(array_merge(
            EventRobots::parse($required),
            $custom,
        ));
    }

    /**
     * Ближайший к «сейчас» сеанс, а если все в прошлом — последний прошедший.
     *
     * Для завершённого концерта «ближайший предстоящий» не существует, но дата
     * в разметке нужна всё равно: Google требует её для Event, а `EventCompleted`
     * без `startDate` — это ошибка, а не особенность. Поэтому сначала ищем
     * ближайший будущий, и только если такого нет — берём самый поздний из
     * прошедших (он и есть дата, когда концерт состоялся).
     */
    private function nearestSession(Event $event): ?object
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

        // Все сеансы в прошлом — отдаём последний.
        return $sessions->last();
    }

    /**
     * Площадка для JSON-LD.
     *
     * `venue_id` объявлен NOT NULL в `sessions`, поэтому у любого валидного
     * события площадка есть. Пустые строки здесь — не «безопасный дефолт»:
     * schema.org/Event требует непустой `location`, и разметка с
     * `addressLocality: ""` отбраковывается валидатором Google.
     *
     * @return array<string, mixed>
     */
    private function venuePayload(Event $event, ?object $session): array
    {
        $venue = $session?->venue ?? $event->sessions->first()?->venue;

        // `region` и `country` в таблице `venues` есть, `postal_code` — НЕТ
        // (проверено по `nabilet_core_spec/migrations.sql`). `StructuredDataService`
        // читает `postal_code` через `??`, поэтому отсутствие ключа безопасно —
        // в разметку уйдёт `addressCountry: 'RU'`, а индекса «несуществующего»
        // поля в JSON-LD не будет.
        return [
            'name'    => $venue?->name ?? '',
            'address' => $venue?->address ?? '',
            'city'    => $venue?->city ?? '',
            'region'  => $venue?->region ?? '',
            'country' => $venue?->country ?? 'RU',
        ];
    }

    /**
     * Заголовок страницы с учётом жизненного цикла (аудит P-05/P-17).
     *
     * `seo_title` из БД побеждает всегда — его написал человек, и спорить с ним
     * автоматика не должна. Но значение ПО УМОЛЧАНИЮ обязано зависеть от
     * статуса: «… — купить билеты» на странице прошедшего или отменённого
     * концерта — это обещание, которого страница не выполняет. Сниппет обещает
     * покупку, пользователь приходит и не находит её; для поисковика это
     * расхождение сниппета и содержимого, то есть повод понизить доверие к
     * выдаче по этому сайту.
     *
     * ДЛИНА НЕ ОБРЕЗАЕТСЯ НАМЕРЕННО (аудит P-21). Название мероприятия в
     * заголовке важнее его длины: поисковик обрезает длинный title только при
     * ПОКАЗЕ и не понижает за это страницу, а вот потерянное из середины название
     * («Новогодний концерт в Крем…») бьёт по релевантности запросу. Обрезка
     * описания — другое дело, там она безопасна, и она делается (см.
     * `buildDescription()`).
     */
    private function buildTitle(Event $event): string
    {
        if (is_string($event->seo_title) && trim($event->seo_title) !== '') {
            return trim($event->seo_title);
        }

        return match ($event->status) {
            EventStatus::COMPLETED => sprintf('%s — мероприятие состоялось', $event->title),
            EventStatus::CANCELLED => sprintf('%s — мероприятие отменено', $event->title),
            EventStatus::ARCHIVED => sprintf('%s — архив мероприятий', $event->title),
            default => sprintf('%s — купить билеты', $event->title),
        };
    }

    /**
     * Фраза о статусе события — для описания, сниппета и видимого блока.
     *
     * `null` для живых статусов: там сообщать нечего, и «Мероприятие состоится»
     * в описании было бы шумом в каждом сниппете.
     */
    private function statusNote(Event $event, ?object $session = null): ?string
    {
        $startsAt = $session?->starts_at;

        return match ($event->status) {
            EventStatus::COMPLETED => $startsAt === null
                ? 'Мероприятие состоялось. Билеты больше не продаются.'
                : sprintf(
                    'Мероприятие состоялось %s. Билеты больше не продаются.',
                    $startsAt->format('d.m.Y'),
                ),
            EventStatus::CANCELLED => 'Мероприятие отменено. Билеты не продаются.',
            default => null,
        };
    }

    /**
     * Видимый серверный блок статуса внутри `#app` (аудит P-05/P-17).
     *
     * ЗАЧЕМ. Метаданные и JSON-LD отдавались сервером, а ВЕСЬ видимый текст
     * появлялся только после исполнения JavaScript. Краулер без JS — а
     * социальные парсеры не исполняют JS никогда — видел страницу прошедшего или
     * отменённого концерта как обычную витрину покупки: разметка говорила
     * `EventCancelled`, а видимого текста об отмене не было вовсе. Расхождение
     * разметки и видимого контента поисковик трактует как введение в
     * заблуждение.
     *
     * ПОЧЕМУ ВНУТРИ `#app`. Vue при монтировании очищает контейнер, поэтому
     * пользователю этот блок не показывается — его заменяет приложение. Для
     * краулера без JS он остаётся. Так «догоняющий» рендер не требует серверного
     * рендеринга всего приложения.
     *
     * @return string уже экранированный HTML
     */
    private function statusBanner(Event $event, ?object $session = null): string
    {
        $headline = match ($event->status) {
            EventStatus::COMPLETED => 'Мероприятие состоялось',
            EventStatus::CANCELLED => 'Мероприятие отменено',
            default => null,
        };

        if ($headline === null) {
            return '';
        }

        return sprintf(
            '<div class="seo-status-fallback" data-event-status="%s">'
            . '<h1>%s</h1>'
            . '<p>%s</p>'
            . '</div>',
            htmlspecialchars((string) $event->status, ENT_QUOTES),
            htmlspecialchars(sprintf('%s «%s»', $headline, (string) $event->title), ENT_QUOTES),
            htmlspecialchars((string) $this->statusNote($event, $session), ENT_QUOTES),
        );
    }

    /**
     * Весь видимый серверный текст внутри `#app` (аудит P-05/P-17/P-20).
     *
     * ДВА БЛОКА, А НЕ ОДИН, И ЭТО НАМЕРЕННО. Блок статуса существует только у
     * терминальных состояний и отвечает на вопрос «что случилось»; контентный
     * блок есть у всех и отвечает на вопрос «что это». Сливать их в один
     * пришлось бы ценой заголовка `<h1>`: у терминального состояния он несёт
     * статус («Мероприятие состоялось «X»»), у живого — само название. Два
     * `<h1>` на странице — это не «больше контекста», а два конкурирующих
     * заголовка в выдаче.
     *
     * Поэтому `contentFallback()` получает флаг: заголовок печатает он, только
     * если блока статуса нет. Ровно один `<h1>` на страницу в любом статусе.
     *
     * @param  array<string, mixed>  $offer
     * @return string уже экранированный HTML
     */
    private function bodyFallback(Event $event, ?object $session, array $offer): string
    {
        $banner = $this->statusBanner($event, $session);

        return $banner . $this->contentFallback($event, $session, $offer, $banner === '');
    }

    /**
     * Контентный серверный блок (аудит P-20).
     *
     * ЗАЧЕМ. Метаданные, JSON-LD и (после P-05/P-17) блок статуса отдавались
     * сервером, но у живого события ВЕСЬ видимый текст появлялся только после
     * исполнения JavaScript: в HTML лежал пустой `<div id="app">`. Google такой
     * контент отрисует с задержкой, Яндекс — хуже, а парсеры соцсетей не
     * исполняют JS никогда, поэтому карточка ссылки строилась из одних
     * метатегов. Серверный текст снимает зависимость индексации от рендеринга.
     *
     * ПОЧЕМУ ВНУТРИ `#app`. Vue при монтировании очищает контейнер, поэтому
     * пользователь этот блок не видит — его заменяет приложение. Это не
     * «догоняющий» рендер всего приложения, а ровно тот минимум, которого
     * краулеру не хватает: заголовок, дата, площадка, лид и цена.
     *
     * ДУБЛИРОВАНИЕ С МЕТАДАННЫМИ — НЕ ОШИБКА. Описание здесь берётся из
     * `short_description`/`description` (человеческий текст события), а не из
     * `buildDescription()`, который для терминальных состояний подменяет текст
     * статусной фразой ради сниппета. Страница и сниппет решают разные задачи:
     * сниппет должен сообщить статус, страница — рассказать о событии.
     *
     * @param  array<string, mixed>  $offer
     * @return string уже экранированный HTML
     */
    private function contentFallback(
        Event $event,
        ?object $session,
        array $offer,
        bool $withHeading,
    ): string {
        $parts = [];

        // Заголовок печатается только когда блока статуса нет — см. `bodyFallback()`.
        if ($withHeading) {
            $parts[] = sprintf('<h1>%s</h1>', htmlspecialchars((string) $event->title, ENT_QUOTES));
        }

        $lines = [
            'seo-fallback__when'  => $this->sessionLine($event, $session),
            'seo-fallback__where' => $this->venueLine($event, $session),
            'seo-fallback__lead'  => $this->leadText($event),
            // Заметка «билетов нет» выводится только когда блока статуса нет:
            // у терминальных состояний он уже сказал, что продажа закрыта, и
            // «Все билеты проданы» рядом с «мероприятие отменено» было бы
            // повтором, который читается как противоречие.
            'seo-fallback__price' => $this->priceLine($offer, announceSoldOut: $withHeading),
        ];

        foreach ($lines as $class => $text) {
            if ($text !== null) {
                $parts[] = sprintf(
                    '<p class="%s">%s</p>',
                    $class,
                    htmlspecialchars($text, ENT_QUOTES)
                );
            }
        }

        // Исполнители — ссылки на страницы артистов (стратегия SEO, B-1).
        //
        // ЗАЧЕМ ИМЕННО ССЫЛКА. Страница артиста существует под запрос «Би-2
        // билеты», и попасть на неё краулер может двумя путями: из
        // `sitemap-artists.xml` и по ссылке. Карта лишь сообщает о существовании
        // адреса — вес по ней не перетекает. Ссылка со страницы события, самого
        // обходимого типа страниц, делает артиста частью обычного обхода, а не
        // отдельным островом, известным только карте.
        //
        // НЕ В JSON-LD, А В ТЕЛЕ. `performer` в разметке события выводится
        // только при наличии данных и служит для расширенного сниппета; ходить
        // по ссылкам робот умеет из HTML. Одно другого не заменяет, поэтому
        // ссылка живёт здесь.
        $artists = $this->artistLinks($event);

        if ($artists !== '') {
            $parts[] = $artists;
        }

        // Блок без содержания не выводится: пустой `<div>` — это тот же «тонкий
        // контейнер», от которого избавляемся, только с классом.
        if ($parts === []) {
            return '';
        }

        // Ссылка на афишу. Краулер без JS не может пройти по SPA-навигации, а
        // серверный блок — единственное место, где для него существует переход
        // дальше этой страницы.
        $parts[] = sprintf(
            '<p class="seo-fallback__links"><a href="%s">Афиша всех мероприятий</a></p>',
            htmlspecialchars(url('/'), ENT_QUOTES)
        );

        return sprintf(
            '<div class="seo-content-fallback" data-event-status="%s">%s</div>',
            htmlspecialchars((string) $event->status, ENT_QUOTES),
            implode('', $parts)
        );
    }

    /**
     * Ссылки на артистов события для серверного блока (стратегия SEO, B-1).
     *
     * АДРЕС СТРОИТСЯ ЧЕРЕЗ `route()`, А НЕ СКЛЕЙКОЙ. Формат адреса страницы
     * артиста — публичный контракт, и он уже один раз менялся у событий (см.
     * `resolveCanonical()`); копия строки здесь разошлась бы с маршрутом молча.
     *
     * `landing.artist` зарегистрирован в `Seo/routes/web.php`, который
     * подключается из `routes/web.php` вместе с этим маршрутом, поэтому имя
     * доступно на момент ответа. Если маршрут когда-нибудь уедет, `route()`
     * бросит `RouteNotFoundException` на первом же рендере страницы события —
     * это громкий отказ, а не тихо пропавшая ссылка.
     *
     * ПУСТОЙ СПИСОК ДАЁТ ПУСТУЮ СТРОКУ, А НЕ ПУСТОЙ АБЗАЦ: у события без
     * исполнителей (обычное состояние до прогона `artists:sync`) блок не должен
     * появляться вовсе — `<p>` с одним словом «Исполнители» и без ссылок это
     * ровно та «тонкая» разметка, от которой избавляется весь серверный слой.
     *
     * @return string уже экранированный HTML либо пустая строка
     */
    private function artistLinks(Event $event): string
    {
        $links = [];

        foreach ($event->artists as $artist) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars(
                    route('landing.artist', ['slug' => (string) $artist->slug]),
                    ENT_QUOTES
                ),
                htmlspecialchars((string) $artist->name, ENT_QUOTES)
            );
        }

        if ($links === []) {
            return '';
        }

        return sprintf(
            '<p class="seo-fallback__artists">Исполнители: %s</p>',
            implode(', ', $links)
        );
    }

    /**
     * Строка «когда» для серверного блока.
     *
     * Формулировка нейтральная («Дата и время»), а не «Ближайший сеанс»:
     * `nearestSession()` для прошедшего события возвращает ПОСЛЕДНИЙ сеанс, и
     * «ближайший» на странице состоявшегося концерта было бы прямой неправдой —
     * ровно та рассинхронизация текста и реальности, из-за которой появился P-05.
     */
    private function sessionLine(Event $event, ?object $session): ?string
    {
        $startsAt = $session?->starts_at;

        if ($startsAt === null) {
            return null;
        }

        $line = sprintf('Дата и время: %s', $startsAt->format('d.m.Y, H:i'));

        $total = $event->sessions->count();

        if ($total > 1) {
            $line .= sprintf(' (всего сеансов: %d)', $total);
        }

        return $line;
    }

    /**
     * Строка «где» для серверного блока.
     *
     * Пустые части пропускаются: `address` в `venues` необязателен, и
     * «Площадка: КЗ «Сургут», , » с висящими запятыми выглядит как сбой
     * рендеринга. Название обязательно — без него строки нет вовсе.
     */
    private function venueLine(Event $event, ?object $session): ?string
    {
        $venue = $this->venuePayload($event, $session);

        $name = trim((string) ($venue['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $rest = array_filter([
            trim((string) ($venue['city'] ?? '')),
            trim((string) ($venue['address'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $rest === []
            ? sprintf('Площадка: %s', $name)
            : sprintf('Площадка: %s, %s', $name, implode(', ', $rest));
    }

    /**
     * Лид события для серверного блока.
     *
     * `short_description` побеждает: это текст, написанный для человека.
     * `description` может быть HTML из редактора, поэтому теги снимаются —
     * вставлять чужую разметку внутрь `#app` нельзя, а `v-html` в проекте
     * запрещён для пользовательского текста. Обрезка на 500 символах: это
     * серверный блок для краулера, а не полная страница.
     */
    private function leadText(Event $event): ?string
    {
        foreach ([$event->short_description, $event->description] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $clean = trim(strip_tags($candidate));

            if ($clean !== '') {
                return \Illuminate\Support\Str::limit($clean, 500);
            }
        }

        return null;
    }

    /**
     * Строка цены для серверного блока.
     *
     * Цена берётся из ТОГО ЖЕ `$offer`, что уезжает в JSON-LD, поэтому текст на
     * странице и разметка не могут разойтись. Форматируется через `Money`, а не
     * `number_format` по строке: разделитель разрядов и знак валюты должны
     * совпадать с остальным интерфейсом.
     *
     * @param  array<string, mixed>  $offer
     * @param  bool  $announceSoldOut  печатать ли «Все билеты проданы», когда цены нет
     */
    private function priceLine(array $offer, bool $announceSoldOut): ?string
    {
        $price = $offer['price'] ?? null;

        if (is_string($price) && $price !== '') {
            $currency = is_string($offer['currency'] ?? null) ? $offer['currency'] : 'RUB';

            return sprintf('Билеты от %s', Money::fromDecimal($price, $currency)->format());
        }

        // Цены нет, но билетов и не осталось — это тоже полезное утверждение.
        // `sold_out` у `cancelled` не бывает: там `offerPayload()` отдаёт
        // `cancelled`, поэтому отменённое событие сюда не попадёт.
        return $announceSoldOut && ($offer['availability'] ?? null) === 'sold_out'
            ? 'Все билеты проданы'
            : null;
    }

    /**
     * Описание для meta/og.
     *
     * Отдельным методом, потому что приоритет здесь отличается от приоритета
     * в разметке: `seo_description` (написанный человеком) побеждает всегда,
     * а дальше берётся самое короткое из осмысленных — длинный `description`
     * в meta-теге обрезается поисковиком на ~160 символах.
     *
     * ДЛЯ ПРОШЕДШЕГО/ОТМЕНЁННОГО СОБЫТИЯ описание по умолчанию сообщает статус
     * (аудит P-05/P-17). Сниппет «Билеты на „X“: выбор мест на схеме зала,
     * оплата онлайн» на странице отменённого концерта — это приглашение купить
     * то, чего нет, и самый заметный текст в выдаче.
     */
    private function buildDescription(Event $event, ?object $session = null): string
    {
        if (is_string($event->seo_description) && trim($event->seo_description) !== '') {
            return trim($event->seo_description);
        }

        // Статус важнее `short_description`: тот написан при создании события и
        // после его завершения никем не обновляется, поэтому продолжает
        // приглашать на концерт, который уже прошёл. Явный `seo_description`
        // выше статуса — это осознанное переопределение автором.
        $statusNote = $this->statusNote($event, $session);

        if ($statusNote !== null) {
            return $statusNote;
        }

        if (is_string($event->short_description) && trim($event->short_description) !== '') {
            return trim($event->short_description);
        }

        if (is_string($event->description) && trim($event->description) !== '') {
            // Обрезаем до длины, которую поисковик реально показывает (~160
            // символов), а не до 300 (аудит P-21): сниппет всё равно обрезался,
            // но обрезал его поисковик — по своему счёту символов и не всегда по
            // границе слова. Здесь это делается на границе слова, поэтому текст
            // в выдаче выглядит законченным.
            return \Illuminate\Support\Str::limit(trim(strip_tags($event->description)), 160);
        }

        return sprintf(
            'Билеты на «%s»: выбор мест на схеме зала, оплата онлайн, QR-билет.',
            $event->title
        );
    }

    /**
     * Цепочка хлебных крошек для страницы мероприятия (аудит P-09).
     *
     * УРОВЕНЬ КАТЕГОРИИ ДОБАВЛЕН ВМЕСТЕ С ПОСАДОЧНОЙ СТРАНИЦЕЙ КАТЕГОРИИ
     * (аудит P-12, `/category/{slug}`). Раньше его здесь не было намеренно:
     * `BreadcrumbList` — не украшение, Google ОБХОДИТ адреса из `item` и битую
     * ссылку показывает в Search Console как «URL not found», понижая доверие ко
     * всей разметке страницы. Пока страницы категории не существовало, поставить
     * туда было нечего, а hash-адрес витрины (`/#/`) разрешается в тот же `/`,
     * что и первый уровень, — то есть две крошки вели бы на одну страницу.
     *
     * УРОВЕНЬ ДОБАВЛЯЕТСЯ ТОЛЬКО КОГДА АДРЕС ЗАВЕДОМО ОТВЕЧАЕТ 200. Это не
     * перестраховка: `LandingController::category()` отдаёт 404 для категории
     * без единого публично видимого события, поэтому ссылка на неё была бы
     * именно той битой ссылкой, ради которой уровень и не ставили. Условие
     * проверяет ровно это и ничего не стоит: если САМО событие публично видимо
     * и его категория активна, то страница категории гарантированно содержит это
     * событие — и, значит, отвечает 200. Отдельного запроса к базе не нужно.
     *
     * Для `scheduled`/`cancelled`/`archived` уровень не выводится: их страницы
     * остаются доступными по прямой ссылке (аудит P-01), но помечены `noindex`, и
     * их категория может не иметь ни одного публичного события. Короткая цепочка
     * валидна — спецификация schema.org и требования Google допускают пропуск
     * промежуточных уровней (обязательны первый и последний).
     *
     * Последний уровень получает КАНОНИЧЕСКИЙ адрес: это требование к разметке
     * (последняя позиция обязана совпадать с canonical страницы), и оно же
     * делает цепочку самопроверяемой в тесте.
     *
     * @return list<array{name: string, url: string}>
     */
    private function breadcrumbTrail(Event $event, string $canonical): array
    {
        $trail = [
            ['name' => 'Главная', 'url' => rtrim((string) config('app.url'), '/') . '/'],
        ];

        $category = $this->categoryCrumb($event);

        if ($category !== null) {
            $trail[] = $category;
        }

        $trail[] = ['name' => (string) $event->title, 'url' => $canonical];

        return $trail;
    }

    /**
     * Уровень категории для крошек — или `null`, если ставить его нельзя.
     *
     * `Route::has()` здесь не формальность. Маршруты модуля Seo подключаются
     * файлом `routes/web.php`, и если он однажды не подключится, `route()` бросит
     * `RouteNotFoundException` — то есть СТРАНИЦА МЕРОПРИЯТИЯ, ключевая страница
     * сайта, начнёт отвечать 500 из-за одного уровня крошек. Проверка превращает
     * это в безобидную деградацию до двух уровней.
     *
     * @return array{name: string, url: string}|null
     */
    private function categoryCrumb(Event $event): ?array
    {
        if (! in_array($event->status, EventStatus::publiclyVisible(), true)) {
            return null;
        }

        if (! Route::has('landing.category')) {
            return null;
        }

        $category = $event->category;

        if ($category === null || (string) $category->status !== 'active') {
            return null;
        }

        $slug = (string) $category->slug;

        if ($slug === '') {
            return null;
        }

        return [
            'name' => (string) $category->name,
            'url' => route('landing.category', ['slug' => $slug]),
        ];
    }
}