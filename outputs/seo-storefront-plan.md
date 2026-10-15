# SEO-разбор витрины NABILET

**Заказчик:** «Сургут-Концерт» (организатор, не перекупщик).
**Источник:** `Мысли о SEO.txt` (стратегическая записка, 1586 строк).
**Задача документа:** разобрать записку глазами SEO-специалиста, соотнести её с тем, что в проекте реально уже есть, и зафиксировать, что делать в MVP, что отложить, а что отклонить.

---

## 0. Executive summary

Записка описывает правильную стратегию, но написана без учёта двух фактов о нашем коде:

1. **Витрина — hash-SPA** (`createWebHashHistory`). Для поисковика это один-единственный URL `/`. Все «красивые URL» из записки с этой архитектурой **не работают вообще**, какую бы сущность мы ни заводили.
2. **Уже существует серверный SEO-слой** (`EventPageController`), который обходит это ограничение точечно: отдаёт реальный HTML по `/event/{slug}/{publicId}`. Он недоделан, но направление верное — и именно его надо расширять, а не строить «сетку сущностей» рядом с ним.

**Главный вывод:** прежде чем заводить `Artist`, `City`, `Genre`, `Guide`, `ArchiveEvent`, `Collection`, `Review`, `UserFollow`, `UserInterest` (10 новых сущностей из записки), нужно починить фундамент: URL-схему, роутинг, sitemap и каноникализацию. Иначе получится 10 новых таблиц и по-прежнему **один индексируемый URL**.

**Что уже работает** (см. §1) — `robots`-поля нет, `robots.txt` нет, sitemap есть, JSON-LD есть, серверный HTML страницы события есть.

**Что критично сломано** (см. §2) — все три показателя: canonical-хост, отсутствие `robots.txt`, отсутствие `noindex` на служебных страницах.

---

## 1. Что уже реализовано (не начинать с нуля)

### 1.1 Модуль `app/Modules/Seo/`

| Компонент | State | Комментарий |
|---|---|---|
| `SitemapService` | ✅ работает | index + events + static, кэш 1 час, `MAX_URLS_PER_SITEMAP = 50 000` |
| `StructuredDataService` | ✅ работает | schema.org/Event с Offer/Place/Organization |
| `SitemapController` | ✅ работает | `/api/v1/sitemap.xml`, `/sitemap-events.xml`, `/sitemap-static.xml` |
| `SeoServiceProvider` | ✅ зарегистрирован | но **дублирует** загрузку роутов (см. §2.6) |

`SitemapService::getCanonicalUrl()` уже уважает `events.canonical_url` и падает на `route('events.show', [slug, publicId])` — то есть **контракт `/events/{slug}-{publicId}` уже заложен**, просто не доведён.

### 1.2 Серверный HTML для страницы события

`EventPageController::show()` — самое ценное, что есть. Он:

- берёт `dist/index.html` и **переписывает** `<title>`, `<meta name="description">`, добавляет `<link rel="canonical">`, `og:*` и `<script type="application/ld+json">`;
- вставляет `<base href="/" />` — иначе относительные `./assets` из dist ломаются на вложенных путях;
- отдаёт **301** с `/event/{slug}` на `/event/{slug}/{publicId}`;
- возвращает 404, если событие `draft` или `archived`.

Это ровно тот «SSR-минимум», без которого SEO обсуждать нечего. Дальше его надо развивать, а не заменять.

### 1.3 Колонки под SEO в спеке уже есть

`nabilet_core_spec/migrations.sql`:

- `events`: `slug`, `seo_title`, `seo_description`, `canonical_url`, `robots`, `published_at`;
- `event_translations`: `locale`, `title`, `short_description`, `description`, `seo_title`, `seo_description`;
- отдельная таблица `seo_meta` (`entity_type`, `entity_id`, `locale`, `title`, `description`, `canonical_url`, `robots`, `og_title`, `og_description`, `og_image`, `schema_json`);
- отдельная таблица `redirects` (`source`, `destination`, `status_code` DEFAULT 301, `active`) — **то самое `url_redirects` из записки, только называется `redirects`**.

**Важно:** `events.canonical_url` и `events.robots` — это готовые точки кастомизации, которые сейчас никто не использует (см. §2.4).

### 1.4 Существующие сущности-«каркас»

- `event_categories` — таблица есть, `slug` есть, `EventCategoryResource` есть. Это **готовый «жанр»** из записки, просто назван иначе.
- `venues` — таблица есть, `slug` есть, `VenueController` есть (публичный `GET /api/v1/venues/{venue}` без авторизации).
- `venue_translations` — есть.
- `organizations` — есть `slug`, `logo`, `description`, `email`, `phone` — из этого собирается страница «Об организаторе» / «О Сургут-Концерте».

**Чего нет вообще:** `artists`, `cities`, `guides`, `articles`, `collections`, `reviews`, `user_follows`, `user_interests`. Ноль упоминаний в спеке, ноль кода.

---

## 2. Критические проблемы, которые надо решить до любой «сетки сущностей»

### 2.1 🔴 URL-схема: витрина живёт на хэшах

`resources/js/router/index.ts:94` — `createWebHashHistory()`. В комментарии объяснение: SPA живёт и внутри чужого `<iframe>` (embed-виджет), где нормальные URL невозможны.

**Что это значит для SEO:**

Реальные адреса витрины:

```
https://nabilet.ru/#/                     ← каталог
https://nabilet.ru/#/event/bi-2           ← событие
https://nabilet.ru/#/event/bi-2/seats     ← выбор мест
```

**Всё после `#` поисковик игнорирует.** Для Google и Яндекса это ровно один URL `https://nabilet.ru/` с одинаковым содержимым.

Записка предлагает `/events/{slug}-{public_id}`, `/artists/bi-2`, `/venues/crocus-city-hall`, `/cities/moscow`, `/genres/rock`. **Ни один из этих URL не будет существовать**, пока витрина хэшовая — если только не сделать для каждого серверную обёртку, как сделано для `EventPageController`.

**Два варианта:**

| | A. History API + серверный fallback | B. Расширять серверные обёртки |
|---|---|---|
| Что делаем | `createWebHistory`, все не-API пути отдают `dist/index.html`, SPA сама рендерит | Оставляем hash внутри SPA, но каждый SEO-URL получает свой контроллер, отдающий переписанный `dist/index.html` |
| Плюс | Один механизм, любые URL, привычно | Embed-виджет не ломается, риск ниже |
| Минус | Нужно править embed (он не может отдавать чистые URL из чужого домена), нужен nginx-конфиг | Каждая новая сущность — новый контроллер + новый метод рендера; дублирование |
| Вердикт | **Правильный, но не сейчас** | **Для MVP** |

**Рекомендация:** в MVP — вариант B (`EventPageController` уже написан), но **сразу вынести переписывание HTML в один переиспользуемый сервис** (`SpaShellRenderer`), чтобы `VenuePageController`, `CategoryPageController`, `CatalogPageController` не копировали `renderSpaShell()`. Иначе получим 5 копий `preg_replace` с разными багами.

Переход на вариант A — отдельная задача, после MVP. Embed-виджет (`resources/js` + `app/Modules/Embed/`) — единственное реальное препятствие.

### 2.2 🔴 `robots.txt` отсутствует физически

`public/` содержит **только `index.php`**. Нет:

- `robots.txt`;
- `favicon.ico`;
- `manifest.webmanifest` (при том что проект заявлен как PWA);
- `apple-touch-icon`;
- OG-заглушки `/images/og-default.jpg`, на которую ссылается `StructuredDataService::buildImageUrls()` (строка 153) — **эта ссылка ведёт в 404 уже сейчас**.

**Что должен содержать `robots.txt`** (это не формальность, а управление краулинговым бюджетом и защита от индексации служебного):

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /checkout
Disallow: /payment
Disallow: /api/v1/
Disallow: /api/v1/tickets
Disallow: /api/v1/my-tickets
Disallow: /install

Sitemap: https://nabilet.ru/api/v1/sitemap.xml
```

Обратите внимание: `Sitemap:` указывает на `/api/v1/sitemap.xml` — потому что роуты модуля Seo подключены **внутри API-группы** (`routes/api.php:44`). Для поисковика это работает, но выглядит как техническая деталь. Если делать «правильно» — sitemap должен жить на `/sitemap.xml`. Об этом §2.6.

### 2.3 🔴 SPA-роуты служебных страниц индексируются без ограничений

Как только появится history-роутинг (или серверные обёртки для `/checkout`, `/tickets`), в индекс попадут страницы с чужими данными. Уже сейчас `GET /api/v1/my-tickets` намеренно вынесен **вне `auth:api`** и скоупится по `X-Cart-Token` — то есть эндпоинт публичный по дизайну. Индексировать его нельзя.

**Что нужно:** `noindex, nofollow` на `/checkout*`, `/payment/*`, `/tickets`, `/cart`, любые `/admin/*`. Плюс `robots.txt` (см. §2.2) как второй рубеж. И **никогда** не отдавать серверный HTML с `og:image`, ведущим на персональный билет.

### 2.4 🟡 `canonical_url` и `robots` в БД есть — код их игнорирует

`EventPageController` строит canonical **всегда сам** (`route('events.show', ...)`), хотя в записи есть `events.canonical_url`. `SitemapService::getCanonicalUrl()` уважает поле, `StructuredDataService::getCanonicalUrl()` тоже — а контроллер нет. **Рассинхрон:** sitemap отправит поисковик на `canonical_url`, а страница объявит canonical сама себя по-своему. Это классическая причина «Страница исключена: alternate page with proper canonical tag».

**Фикс:** в `EventPageController` поставить тот же приоритет, что и в сервисах:
```php
$canonical = $event->canonical_url ?: route('events.show', [...]);
```
`events.robots` — вообще не читается. Если организатор проставит `noindex` в админке (когда она появится), поле должно попадать в `<meta name="robots">`.

### 2.5 🔴 `publish()` недостижим — статус события меняется в обход правил

`EventService::publish()` есть, `EventPublicationPolicy`, `PublicationDecision`, `canPublish()` — есть. **Ни одного роута и контроллера, которые их вызывают — нет.**

Следствие для SEO: `EventStatus::publiclyVisible()` возвращает `[PUBLISHED, COMPLETED]`, и `SitemapService` фильтрует по нему. Но никто не мешает записать `status = 'published'` напрямую через `PUT /api/v1/events/{event}` — валидация `EventStatus::isValid()` проверит только то, что строка из списка.

**Риск:** в sitemap и в индекс попадёт событие без постера, без сессии, без зала — «тонкая страница» (thin content) с одной строкой «концерт Би-2» и без цены. Google такие страницы понижает, а в масштабе раздела — применяет понижение ко всему сайту.

**Фикс (в MVP):** публикация только через `POST /api/v1/events/{event}/publish`, который вызывает `EventPublicationPolicy` и возвращает причины отказа; `status` исключить из `UpdateEventRequest` (сейчас он там есть неявно — см. `NON_COLUMN_FIELDS`).

### 2.6 🟡 Роуты sitemap подключены дважды

`SeoServiceProvider::boot()` → `loadRoutesFrom(routes/api.php)`, **и одновременно** `routes/api.php:44` → `require` того же файла. При этом провайдер не в `bootstrap/providers.php`.

Сейчас это «работает», потому что провайдер не зарегистрирован — то есть фактически работает только центральное подключение. Но стоит кому-то добавить `SeoServiceProvider` в `bootstrap/providers.php` (а это логичный шаг при «нормализации провайдеров») — роуты зарегистрируются дважды, и Laravel либо перезапишет имена, либо упадёт на дубликате `sitemap.index`.

**Плюс пострадает префикс:** `loadRoutesFrom` без группы даст `/sitemap.xml`, а не `/api/v1/sitemap.xml`, — и `robots.txt` из §2.2 начнёт врать.

**Фикс:** убрать `boot()` из `SeoServiceProvider` (или привести к общему паттерну модулей — но это отдельная задача, см. `bootstrap/providers.php`).

### 2.7 🟡 JSON-LD содержит пустые поля

`EventPageController` передаёт в `StructuredDataService`:

```php
'venue'        => ['name' => ''],                          // строка 80
'organization' => $event->organization?->toArray() ?? ['name' => ''],
'start_datetime' => $event->start_datetime,                // свойства НЕТ
```

- `venue` жёстко пустой, хотя `$event->load(['sessions'])` уже загружен — и в каждой сессии есть `venue_id` / `hall_id`. То есть **площадка реально известна**, просто не доложена.
- `$event->organization->toArray()` — вываливает всю модель организации в JSON-LD. Туда уедет почта, телефон, `settings_json`, служебные поля. Наружу это видно в коде страницы.
- **`start_datetime` — не существует ни в таблице `events`, ни в модели.** Проверено: `grep -rn "start_datetime" app/` даёт только три места — контроллер и два места в `StructuredDataService`. В спеке дата хранится в `sessions.starts_at` (`DATETIME(6) NOT NULL`).

`StructuredDataService` делает `$eventData['start_datetime'] ?? null` → `null` → `formatDateTime(null)` → `null`.

**Итог: JSON-LD с `"startDate": null` невалиден по требованиям Google.** Это не «предупреждение», это исключение события из rich-результатов — то есть главный видимый выигрыш от всей затеи с schema.org теряется.

**Фикс:** брать `starts_at` первой/ближайшей сессии:
```php
'start_datetime' => $event->sessions->min('starts_at')?->toIso8601String(),
'venue' => [
    'name'    => $event->sessions->first()?->venue?->name ?? '',
    'address' => $event->sessions->first()?->venue?->address ?? '',
    'city'    => $event->sessions->first()?->venue?->city ?? '',
],
'organization' => ['name' => $event->organization?->name ?? 'Сургут-Концерт'],
```

### 2.8 🟡 `slug` уникален только внутри организации

`UNIQUE KEY uq_events_org_slug (organization_id, slug)`. При одной организации — не проблема. Но `EventRepository::findBySlug()` делает `where('slug', $slug)->first()` **без фильтра по организации**. Если когда-нибудь появится вторая организация с тем же slug — вернётся случайная запись.

Для SEO это означает: два разных события по одному URL, и поисковик выберет одно. При одном организаторе в MVP риск нулевой, но **фикс стоит одну строку** — добавить в запрос `organization_id` из контекста.

---

## 3. Разбор идей записки: что берём, что нет

### 3.1 ✅ Берём в MVP

#### «Не удалять завершённые мероприятия» — да, и это уже частично работает

Записка права, и код уже на её стороне: `EventStatus::publiclyVisible()` = `[PUBLISHED, COMPLETED]`. Завершённое событие **уже** остаётся в sitemap. `published_at <= now()` в `getEventsSitemap()` — фильтр по времени публикации, а не по дате концерта, так что прошедшие концерты не выпадают.

**Что доделать:**
1. `EventPageController` возвращает **404 для `archived`**. Записка предлагает `ARCHIVE` как отдельное состояние с полноценной страницей — значит `archived` не должен быть 404. Сейчас это противоречие между спекой и кодом.
2. Шаблон `EventPage.vue` должен уметь «Мероприятие завершено» вместо кнопки «Купить». Данные для этого есть (`event.status`).
3. В `@type: Event` для завершённого ставить `eventStatus: EventCompleted` (в `StructuredDataService` уже есть маппинг) и **убирать `offers`** — иначе Google видит предложение билетов на прошедший концерт.

#### Разделение Event и Session — да, но оно УЖЕ сделано

Записка предлагает ввести разделение «событие / сеанс». В спеке это **уже есть**: `events` и `sessions` — разные таблицы, `sessions.event_id → events.id`, у сессии свои `starts_at`, `sales_start_at`, `status`.

**Не нужно ничего проектировать — нужно правильно отразить это в URL.** Сейчас есть только `/event/{slug}/{publicId}` — это URL события, и он один. Сеансы живут внутри как параметр `?session=` (`router/index.ts:24` — `event/:slug/seats`).

**Рекомендация:** оставить один URL на событие (как предлагает записка в §33), а сеансы — не индексировать вообще (`noindex`), потому что контент дублируется с точностью до даты. Это дешевле, чем `/events/{slug}/{date}` и последующая борьба с дублями.

#### `public_id` immutable + `slug` mutable — да, и это уже так

- `public_id CHAR(26)` (ULID) — `UNIQUE KEY uq_events_public_id`, нигде не обновляется.
- `slug VARCHAR(255)` — `UNIQUE KEY uq_events_org_slug`, меняется через `UpdateEventRequest`.

Возражение к записке: она предлагает **числовой** ID (`348671`) вместо ULID, аргумент — «короче». Но `public_id` уже ULID во всех 30+ таблицах спеки; менять его на `id BIGINT` означает либо светить автоинкремент (перебор ID = утечка объёма бизнеса), либо вводить второе поле. **Оставляем ULID.** Длина URL — не SEO-фактор.

#### `url_redirects` — есть, называется `redirects`

Таблица `redirects` (`source` UNIQUE(512), `destination`, `status_code` DEFAULT 301, `active`) уже в спеке. **Кода нет ни строчки.**

**MVP-объём:** middleware, который на 404 ищет `source` в `redirects` и отдаёт 301. Плюс при смене `slug` через `PUT /api/v1/events/{event}` — автоматически писать старый URL в `redirects`. Это ровно тот механизм, который записка описывает на 60 строках, и он уже наполовину в БД.

**Важно:** `redirects` не имеет колонки под организацию — при одной организации это ок.

### 3.2 ⚠️ Берём, но позже (P2)

| Идея записки | Вердикт | Почему позже |
|---|---|---|
| Страницы артистов `/artists/{slug}` | P2 | Нужна новая сущность `artists` + связь `event_artists`. Пока нет — но у «Сургут-Концерта» артисты повторяются, это самый перспективный раздел после площадок |
| Страницы площадок `/venues/{slug}` | **P1.5** | Таблица `venues` + `slug` + публичный `VenueController::show` **уже есть**. Не хватает `VenuePageController` (копия `EventPageController`) и `sitemap-venues.xml` — комментарий в `SitemapService` прямо объясняет, почему этого нет: «A venue page has to exist before it can be indexed» |
| Страницы городов `/cities/{slug}` | P2 | Категории города нет как сущности. `halls.city` и `venues.city` есть, но города отдельной таблицей нет. Для «Сургут-Концерта» (один город) — избыточно в MVP |
| Жанры `/genres/{slug}` | P2 | `event_categories` уже есть и уже имеет `slug` — но публичной страницы нет |
| Архив `/events/archive` | P2 | Хорошая идея, но требует отдельного листинга с пагинацией и серверным HTML. В MVP роль архива выполняет сам факт того, что `COMPLETED` остаётся в sitemap |
| «Сегодня / выходные», «До 1000 ₽», «Бесплатные» | P2 | Это фильтры каталога. Реализуются как серверные страницы поверх `EventController::index` с зафиксированным фильтром |
| Календарь мероприятий | P2 | Отдельный листинг |
| Блог / журнал / гид по площадкам | P3 | Нужна CMS. В спеке есть `pages` + `page_translations` — фундамент есть, редактора нет |
| Отзывы | P3 | Нужна модерация, антиспам, юридическая база. Отдельный проект |
| AI-консьерж | P3 | Есть модуль `Ai/`, но это не SEO-задача |
| Подписки / «Мои интересы» | P3 | `UserFollow`, `UserInterest` — новые сущности, ценность для SEO косвенная |

### 3.3 ❌ Отклоняем

| Идея | Причина |
|---|---|
| **Числовой ID в URL** (`/events/bi-2-moskva-348671`) | `public_id` уже ULID везде. Смена — рефакторинг 30+ таблиц ради несуществующего SEO-выигрыша. Google прямо пишет: «сам по себе числовой ID не делает URL плохим» — записка это и цитирует, но всё равно рекомендует числовой |
| **Домен на город** (`surgut.kassa-ugra.ru`) | Записка сама отклоняет; подтверждаем — для одного города бессмысленно, а поддомены дробят ссылочный вес |
| **URL сеанса `/events/{id}/session/78231`** | Создаёт N почти-дублей на событие с разницей в дату. При 20 концертах в год × 10 сеансов = 200 страниц против 20. Канcanonical-борьба дороже выигрыша |
| **«Ретаргетинг»-разделы вроде «Куда сходить с девушкой»** | Это копирайтинг, а не архитектура. Делается в CMS (`pages`), когда будет контент-редактор, а не как сущность |
| **Дополнительные фото/видео через новую таблицу** | В спеке уже есть `media_assets` + `media_links` (полиморфные, с `role` и `position`). Строить новую — дублирование. См. `outputs/event-to-storefront-flow.md` |

---

## 4. Приоритизированный план

### P0 — до любого расширения сущностей

| # | Задача | Файлы | Почему P0 |
|---|---|---|---|
| 1 | `public/robots.txt` | новый | Без него `/admin`, `/checkout`, `/api/v1/` открыты для индексации |
| 2 | `noindex` на служебных роутах | `EventPageController`, роутер | Персональные данные билетов в индексе |
| 3 | Починить `start_datetime` → `sessions.starts_at` в JSON-LD | `EventPageController:77` | Сейчас JSON-LD невалиден, rich-сниппетов не будет |
| 4 | Реальный `venue` в JSON-LD | `EventPageController:80` | Площадка загружена, но не доложена |
| 5 | Убрать `organization->toArray()` из JSON-LD | `EventPageController:81` | Утечка служебных полей в публичный HTML |
| 6 | `canonical_url` / `robots` из БД уважать в контроллере | `EventPageController` | Рассинхрон с sitemap → «alternate page» |
| 7 | Публичная публикация через эндпоинт с `EventPublicationPolicy` | `EventController`, `routes/api.php` | Thin-страницы в индексе |
| 8 | `archived` ≠ 404 | `EventPageController:39` | Противоречие со `SitemapService` |
| 9 | OG-заглушка `/images/og-default.jpg` | `public/` | Ссылка из кода ведёт в 404 |

### P1 — каркас URL

| # | Задача | Заметка |
|---|---|---|
| 10 | Вынести `renderSpaShell()` в `SpaShellRenderer` | Иначе каждая новая сущность копирует `preg_replace` |
| 11 | `VenuePageController` + `/venue/{slug}` | `venues` и `slug` уже есть |
| 12 | `sitemap-venues.xml` | Вернуть после п.11 (комментарий в `SitemapService` сам это предписывает) |
| 13 | Middleware 301 по таблице `redirects` | Таблица есть, кода нет |
| 14 | Авто-запись старого slug в `redirects` при `PUT /events/{event}` | Ровно то, что описывает записка |
| 15 | `findBySlug()` — фильтр по `organization_id` | Одна строка |

### P2 — расширение сетки

16. Публичная страница категории/жанра (`EventCategory` — сущность есть).
17. Архив с пагинацией и серверным HTML.
18. Fixed-filter страницы: сегодня / выходные / до 1000 ₽.
19. Сущность `artists` + `event_artists` + `/artist/{slug}`.
20. Города — только если выйдем за Сургут.

### P3 — контент

21. `pages` + `page_translations` → блог, гиды, «как это было».
22. Отзывы.
23. Подписки.

---

## 5. Отдельно: технический SEO-долг, не связанный с запиской

Записка их не касается, но они влияют на индексацию:

1. **`dist/index.html` содержит `<html lang="ru" class="dark">`** с жёстко прошитой тёмной темой. Инлайновый скрипт переключает класс до отрисовки — для краулера это нормально, но `class="dark"` в статике означает: без JS страница тёмная. **Проверить, что текст читаем при отключённом JS.**

2. **`<meta name="viewport">` и `color-scheme` — единственные мета-теги.** В `dist/index.html` нет `og:*`, нет `twitter:*`. `EventPageController` их добавляет, но только для `/event/*`. **Корень `/` уходит в индекс без OG-разметки** — то есть любая пересылка ссылки на каталог в мессенджере даст пустую карточку.

3. **Хэш-роутинг + `<base href="/">` — конфликт.** `EventPageController` вставляет `<base href="/" />`, а Vue Router внутри использует hash. Для корректной работы `createWebHashHistory` base не нужен — нужен только для `./assets`. Проверить, что `<base href="/">` не ломает относительные ссылки внутри SPA на вложенных путях (`/event/x/y`).

4. **Sitemap не содержит `lastmod` для статических страниц кроме корня** — `getStaticPages()` возвращает массив из одного элемента. Когда появятся `/about`, `/privacy`, `/terms` — они добавляются сюда (комментарий в коде это и предписывает).

5. **Комментарий в `SitemapService:19-23`** объясняет отсутствие venues-sitemap: предыдущий вариант 500-ил на несуществующем `route('venues.show')`. Это правильный урок — «sitemap добавляется вместе со страницей, не раньше». Держать этот принцип как правило.

6. **`resources/js/lib/metrika.ts`** — Яндекс.Метрика подключена. Убедиться, что счётчик не пишет персональные данные (номер заказа, email) в `params` — иначе это нарушение 152-ФЗ и потенциальный ручной фильтр Яндекса.

---

## 6. Ответ на вопрос «один бренд, одна витрина»

Из предыдущих уточнений: **«Сургут-Концерт» — организатор, проект только для него.**

С точки зрения SEO это упрощает многое:

- **Домен — один.** Никаких городов в домене, никаких мультибрендовых поддоменов. `nabilet.ru` (или домен заказчика).
- **`organizations.slug`** используется один раз — для страницы «Об организаторе». Это хорошая E-E-A-T-точка: контакт, телефон, описание, ссылка на официальный сайт.
- **Каркас сайта:** главная → каталог → событие → площадка → категория. Пять типов страниц, не двадцать из записки.
- **Артистов можно не выносить в сущность на MVP** — если у «Сургут-Концерта» 20 концертов в год, афиша сама себя наполняет контентом, и «пустого сайта после последнего концерта» не будет так быстро, как предполагает записка. Приоритет — площадки (их конечное число и они повторяются) и архив.

**Мультитенантность.** Только 16 из 64 таблиц имеют `organization_id`. `events` — имеет, `venues` — имеет. Для SEO-запросов фильтр по организации обязателен по той же логике, что и везде: `OrganizationContext::id()`, не из тела запроса. При одном организаторе это выглядит как формальность — но представьте страницу артиста, собирающую события из `events` без фильтра: она покажет концерты другой организации, если та появится. Fail-closed проще, чем последующая чистка.

---

## 7. Итоговая таблица

| Идея из `Мысли о SEO.txt` | В спеке | В коде | Решение |
|---|---|---|---|
| Не удалять завершённые события | ✅ `published_at`, `status` | ⚠️ `archived` → 404 | **P0** — починить |
| Event ≠ Session | ✅ `events` / `sessions` | ✅ реализовано | Готово, отразить в URL |
| `public_id` immutable | ✅ ULID | ✅ | Готово |
| `slug` mutable | ✅ | ✅ | Готово |
| `url_redirects` | ✅ `redirects` | ❌ | **P1** |
| Площадки `/venues/{slug}` | ✅ `venues.slug` | ⚠️ API есть, страницы нет | **P1.5** |
| Жанры `/genres` | ✅ `event_categories.slug` | ⚠️ API есть, страницы нет | **P2** |
| Артисты `/artists` | ❌ | ❌ | **P2** — новая сущность |
| Города `/cities` | ❌ | ❌ | **P2** — один город |
| Архив `/events/archive` | ⚠️ частично (`COMPLETED` в sitemap) | ⚠️ | **P2** |
| Блог / гид / журнал | ⚠️ `pages` | ❌ | **P3** |
| Отзывы | ❌ | ❌ | **P3** |
| AI-консьерж | ⚠️ модуль `Ai/` | ❌ | **P3** |
| Числовой ID в URL | — | — | ❌ **отклонить** (ULID уже везде) |
| URL сеанса | — | — | ❌ **отклонить** (дубли) |
| Домен на город | — | — | ❌ **отклонить** |

---

**Файловая справка по коду, на который ссылается документ:**

- `app/Modules/Seo/Services/SitemapService.php` — 213 строк, sitemap
- `app/Modules/Seo/Services/StructuredDataService.php` — 238 строк, JSON-LD
- `app/Modules/Seo/Http/Controllers/SitemapController.php`
- `app/Modules/Seo/routes/api.php` — 3 роута
- `app/Modules/Seo/Providers/SeoServiceProvider.php` — **дублирует загрузку роутов**
- `app/Modules/Events/Http/Controllers/EventPageController.php` — серверный SEO-HTML
- `app/Modules/Events/Domain/EventStatus.php` — `publiclyVisible()` = PUBLISHED + COMPLETED
- `app/Modules/Events/Services/EventService.php:97` — `publish()` без вызывающего кода
- `app/Modules/Events/Repositories/EventRepository.php:42` — `findBySlug()` без `organization_id`
- `resources/js/router/index.ts:94` — `createWebHashHistory()`
- `routes/web.php:93` — подключение web-роутов Events
- `routes/api.php:44` — подключение Seo
- `nabilet_core_spec/migrations.sql` — `seo_meta` (706+), `redirects` (722+), `events` (133+)
