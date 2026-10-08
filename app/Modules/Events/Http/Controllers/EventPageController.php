<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Repositories\EventRepository;
use Nabilet\Modules\Events\Services\EventService;
use Nabilet\Modules\Seo\Services\StructuredDataService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

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
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepository $events,
        private readonly StructuredDataService $structuredData
    ) {}

    /**
     * GET /event/{slug}/{publicId}
     * GET /event/{slug}            → редирект на полный URL (canonical).
     */
    public function show(string $slug, ?string $publicId = null): Response | JsonResponse | RedirectResponse
    {
        $event = $this->events->findBySlug($slug);

        if ($event === null) {
            return $this->notFound($slug);
        }

        // КАКОЙ СТАТУС ИМЕЕТ ПРАВО НА СТРАНИЦУ
        //
        // Раньше здесь стояло `status === 'draft' || status === 'archived'` → 404.
        // Это расходилось с `SitemapService`, который фильтрует по
        // `EventStatus::publiclyVisible()` = [published, completed]:
        //
        //   - `draft`     — 404 здесь И не в sitemap. Согласовано.
        //   - `archived`  — 404 здесь И не в sitemap. Тоже согласовано, НО
        //                   `archived` — терминальное состояние по
        //                   `EventStateMachine`, то есть архивация была
        //                   равносильна удалению страницы. При этом в sitemap
        //                   archived не попадает, так что робот однажды
        //                   увидит 404 на проиндексированный ранее URL —
        //                   это soft-404-риск наоборот: жёсткий 404 по
        //                   адресу с историей.
        //   - `scheduled` — 200 здесь И НЕ в sitemap. Событие опубликовано на
        //                   будущее (`published_at` в будущем) — страницу
        //                   отдавать можно, но индексировать рано.
        //   - `cancelled` — 200 здесь И НЕ в sitemap.
        //
        // Приводим одну модель: страницу получает всё, что уже выходило на
        // сайт, а индексируемость регулируется `publiclyVisible()` в sitemap и
        // `noindex` здесь. 404 остаётся только для того, что публика видеть не
        // должна вообще: черновик и архивированное (архив = «больше не
        // поддерживается», см. ниже).
        if (in_array($event->status, [EventStatus::DRAFT, EventStatus::ARCHIVED], true)) {
            return $this->notFound($slug);
        }

        // Канонический URL: /event/{slug}/{publicId} — без publicId редирект.
        //
        // `events.canonical_url` побеждает вычисленный адрес. Это не теоретическая
        // возможность: `SitemapService::getCanonicalUrl()` и
        // `StructuredDataService::getCanonicalUrl()` уже читают это поле, а
        // контроллер его игнорировал — то есть карта сайта отправляла робота на
        // один адрес, а страница объявляла канонической саму себя по другому.
        // Для Google это класс ошибки «Страница исключена: alternate page with
        // proper canonical tag», и он снимает всю страницу с индексации.
        $canonical = $event->canonical_url ?: route('events.show', [
            'slug' => $event->slug,
            'publicId' => $event->public_id,
        ]);

        if ($publicId === null) {
            return response()->redirectTo($canonical, 301);
        }

        // `sessions.venue` тянем сразу: и JSON-LD, и (ниже) дата начала события
        // берутся из ближайшего сеанса. Без `venue` в цепочке `$session->venue`
        // даёт N+1 запрос на каждое место, а мест в зале сотни.
        $event->load(['category', 'translations', 'sessions.venue', 'organization']);

        $title = $event->seo_title ?? sprintf('%s — купить билеты', $event->title);
        $description = $this->buildDescription($event);

        // Дата события живёт НЕ в `events` — в таблице нет ни `start_datetime`,
        // ни `start_date`, есть только `published_at` (момент выхода на сайт).
        // Дата концерта хранится в `sessions.starts_at` (NOT NULL), потому что
        // у одного события может быть несколько сеансов.
        //
        // Раньше здесь передавался `$event->start_datetime` — свойства нет ни в
        // модели, ни в таблице, Eloquent возвращал null, и наружу уходил
        // `"startDate": null`. Google такой JSON-LD отвергает целиком, то есть
        // rich-сниппет не появлялся вообще — а это и был весь смысл разметки.
        $nearestSession = $this->nearestSession($event);

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
        ]));

        $html = $this->renderSpaShell([
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
                : $this->publicUrl('images/og-default.svg'),
            'jsonLd'        => $jsonLd,
            // `events.robots` до этого не читалось вообще: администратор мог
            // проставить `noindex`, и ничего бы не произошло. Значение уходит
            // как есть — это свободный VARCHAR(255), в котором валидны
            // `noindex, nofollow`, `noarchive` и т.п.
            //
            // Если поле пустое, но статус НЕ входит в `publiclyVisible()`
            // (scheduled, cancelled), `noindex` ставится автоматически: такая
            // страница существует и должна быть доступна по прямой ссылке
            // (человек пришёл из письма или из закладки), но в индексе ей
            // делать нечего — в sitemap её тоже нет.
            'robots'        => $event->robots ?: $this->defaultRobots($event),
        ]);

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
     */
    private function storageUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return config('app.url') . '/storage/' . ltrim($path, '/');
    }

    /**
     * Абсолютный URL статического файла из `public/` (favicon, заглушка og).
     *
     * Префикс `/storage/` здесь не добавляется: файл лежит в `public/images`
     * и обслуживается веб-сервером напрямую по `/images/…`.
     */
    private function publicUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return config('app.url') . '/' . ltrim($path, '/');
    }

    /**
     * Единый 404 для страницы события.
     *
     * Тело — в формате §66 envelope, как у остальных JSON-ошибок проекта,
     * чтобы ответ не выглядел как «страница другого приложения» в логах и
     * мониторинге. Отдаётся и для «нет такого slug», и для draft/archived —
     * намеренно одинаково: разница между «не существует» и «существует, но
     * скрыто» — это информация для того, кто перебирает адреса.
     */
    private function notFound(string $slug): Response | JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'EVENT_NOT_FOUND',
                'message' => sprintf('Мероприятие «%s» не найдено.', $slug),
            ],
        ], 404);
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
     * Описание для meta/og.
     *
     * Отдельным методом, потому что приоритет здесь отличается от приоритета
     * в разметке: `seo_description` (написанный человеком) побеждает всегда,
     * а дальше берётся самое короткое из осмысленных — длинный `description`
     * в meta-теге обрезается поисковиком на ~160 символах.
     */
    private function buildDescription(Event $event): string
    {
        foreach ([$event->seo_description, $event->short_description] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        if (is_string($event->description) && trim($event->description) !== '') {
            return \Illuminate\Support\Str::limit(trim(strip_tags($event->description)), 300);
        }

        return sprintf(
            'Билеты на «%s»: выбор мест на схеме зала, оплата онлайн, QR-билет.',
            $event->title
        );
    }

    private function renderSpaShell(array $seo): string
    {
        $indexFile = __DIR__ . '/../../../../../dist/index.html';
        if (! file_exists($indexFile)) {
            return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
                . '<title>NABILET — билеты на события</title></head>'
                . '<body><div id="app"></div></body></html>';
        }

        $html = file_get_contents($indexFile);

        // Заменяем title/description на уникальные для события.
        $html = preg_replace(
            '/<title>.*?<\/title>/s',
            sprintf('<title>%s</title>', htmlspecialchars($seo['title'], ENT_QUOTES)),
            $html
        );
        $html = preg_replace(
            '/<meta name="description"[^>]*>/',
            sprintf('<meta name="description" content="%s" />', htmlspecialchars($seo['description'], ENT_QUOTES)),
            $html
        );

        // canonical + og + JSON-LD — вставляем перед </head>.
        // base href="/": многие страницы живут по пути вроде /event/:slug/seats —
        // без неё относительные ./assets из dist/index.html резолвятся неверно.
        //
        // Плейсхолдеры вместо sprintf: строка со сборкой из восьми позиционных
        // `%s` уже один раз поехала (аргумент `og:image` мог стать `null` и
        // сдвинуть все последующие), а порядок здесь критичен. `strtr` по
        // именам ключей такую ошибку делает невозможной.
        $seoBlock = '<base href="/" />' . strtr(
            '<link rel="canonical" href="{{canonical}}" />'
            . '<meta property="og:type" content="website" />'
            . '<meta property="og:title" content="{{title}}" />'
            . '<meta property="og:description" content="{{description}}" />'
            . '<meta property="og:url" content="{{canonical}}" />'
            . '{{robots}}'
            . '{{image}}'
            . '<script type="application/ld+json">{{jsonLd}}</script>',
            [
                '{{canonical}}'   => htmlspecialchars($seo['canonical'], ENT_QUOTES),
                '{{title}}'       => htmlspecialchars($seo['title'], ENT_QUOTES),
                '{{description}}' => htmlspecialchars($seo['description'], ENT_QUOTES),
                '{{robots}}'      => ($seo['robots'] ?? null)
                    ? sprintf('<meta name="robots" content="%s" />', htmlspecialchars((string) $seo['robots'], ENT_QUOTES))
                    : '',
                '{{image}}'       => ($seo['image'] ?? null)
                    ? sprintf('<meta property="og:image" content="%s" />', htmlspecialchars($seo['image'], ENT_QUOTES))
                    : '',
                // JSON-LD НЕ htmlspecialchars-им: экранирование превратило бы
                // кавычки в `&quot;` и сделало блок невалидным JSON. При этом
                // `<`/`>` внутри строк JSON надо нейтрализовать, иначе `</script>`
                // в описании события закрывает тег и ломает страницу —
                // это классическая XSS через structured data.
                '{{jsonLd}}'      => $this->safeJsonLd($seo['jsonLd']),
            ]
        );

        $html = str_replace('</head>', $seoBlock . '</head>', $html);

        return $html;
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