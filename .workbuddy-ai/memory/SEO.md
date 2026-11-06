# SEO витрины NABILET

Вынесено из `MEMORY.md`, чтобы тот влезал в инъекцию. Детали — `SEO-AUDIT-2026-10-10.md`
и докблоки `app/Modules/Seo/*`.

## Контракты страниц
- Hash-роутинг = один индексируемый URL `/`; серверные страницы — отдельные HTML. Обёртка события — `EventPageController` (`GET /event/{slug}/{publicId}`; без `publicId` ИЛИ с чужим → 301) собирает `dist/index.html` через `strtr()`. Путь — `config('nabilet.storefront.shell_path')`; сборки нет → 503 `STORE_FRONT_NOT_BUILT`, НЕ пустой 200.
- 404 ТОЛЬКО `draft`; `archived` → 200 + `noindex, noarchive`; мягко удалённое → 410 Gone. `canonical_url` из БД — только со своего домена. `X-Robots-Tag` > `<meta robots>` для hash-SPA.
- Редиректы: `Seo\Services\RedirectResolver` + `Seo\Http\Middleware\ResolveRedirect` — GLOBAL stack (таблица `redirects`: `source`/`destination`/`status_code`/`active`). Регистр НЕ понижать (`publicId` — ULID base32). Не трогает не-GET/HEAD, `/api/*`, `/install*`, `/up`; fail-open. Смена `slug` в `EventController::update()` пишет 301.
- JSON-LD `offers`: цена = минимум по ДОСТУПНОМУ инвентарю ближайшего сеанса (`Money::toDecimal()`); `availability` из инвентаря приоритетнее статуса. `route('checkout.select')` НЕ существует. Экранировать только `<`/`>` (`htmlspecialchars()` ломает JSON).
- Публичный `GET /events`: аноним/покупатель → только `EventStatus::publiclyVisible()`; сотрудник — любой `status`. `EventRepository::findBySlug()` БЕЗ скоупа по организации (P-03).

## Посадочные страницы (P-12 — сделано; артист — стратегия B-1)
Адреса: `/venue/{slug}`, `/city/{slug}`, `/category/{slug}`, `/city/{slug}/{category}`,
`/organizer/{slug}`, `/artist/{slug}`. Модуль Seo, `Seo/routes/web.php` (ВЫШЕ `Route::fallback()`),
СВОЙ самодостаточный HTML (`LandingPageRenderer`, без `dist/` и внешних ресурсов),
НЕ оболочка SPA. Пусто и `?page`>last → 404 (не «тонкая страница» и не редирект).

- **Фасет — `/city/{slug}/{category}`, НЕ `/{city}/{category}`** (отклонение от §2.4 аудита): двухсегментный маршрут в корне — маска, молча проглотит будущий `/payment/{id}`.
- Слои: `CatalogService` — запросы; `LandingPageService` — тексты и разметка сущности; `LandingPage` (Domain) хранит `baseUrl` и `entity`, адрес только через `pageUrlFor()`/`pageUrl()` (метод ЭКЗЕМПЛЯРА нужен рендереру пагинации). `PER_PAGE = 20`, `RELATED_LIMIT = 8`.
- Порядок списка — 3 ключа SQL: `MIN(starts_at) WHERE starts_at>=now IS NULL ASC`, затем `MIN(...) ASC`, затем `MAX(starts_at) DESC`. `COALESCE(MIN(будущий),MAX(любой)) IS NULL` группы НЕ разделяет — прошедшие уезжают наверх.
- **Место требует СЕАНС** (`eventsAtVenue`/`eventsInCity`); **фасет каталога — НЕТ** (`eventsInCategory`, `eventsAtOrganization`, `eventsAtArtist`): категория, организатор и артист — фасеты КАТАЛОГА, а каталог фильтруется только по статусу. Иначе набор на странице разошёлся бы с публичным каталогом.
- `categories()`/`organizations()`/`artists()` — LEFT JOIN (сущность без событий остаётся, иначе 404); `categoriesInCity()` — INNER. `event_categories` без `created_at`/`updated_at`, `last_change` = `MAX(events.updated_at)`; у площадки, организатора и артиста `GREATEST(свой updated_at, MAX(events.updated_at))`. У `artists()` ДВА LEFT JOIN: на `artist_event` (артист без событий остаётся) и на `events` с фильтром публичности в `ON` — фильтр в `WHERE` превратил бы join в INNER.
- Крошки: последний уровень = canonical. Уровень категории на странице события — ТОЛЬКО если адрес заведомо 200 (событие публично + категория `active` + `Route::has()`), иначе 2 уровня.
- `Str::slug()` транслитерирует неинтуитивно (`Тюмень→tiumen`, `Брянск→briansk`, `Тверь→tver`); адрес — публичный контракт, в тестах писать ЯВНО. Город — свободный текст `venues.city`, написания сливаются по slug. `artists.slug` — глобально уникален, выбирается ОДИН раз при создании (`artists:sync` его не меняет).
- `venues.slug` уникален В ПРЕДЕЛАХ организации (`uq_venues_org_slug`), НЕ глобально → `venueBySlug()` однозначен только пока клиент один (P-03). `organizations.slug` и `artists.slug` — глобально уникальны.
- `Place` — на странице площадки, `Organization` — на странице организатора, `MusicGroup` — на странице артиста. `addressCountry` — единственный дефолт (`RU`): без него `address` выродился бы в `{"@type":"PostalAddress"}`, а его `pruneEmpty` НЕ убирает.
- Карты `sitemap-{events,static,venues,cities,categories,organizers,artists}` — В КОРНЕ (аудит P-10a, сделано 2026-10-15), `Content-Type: text/xml; charset=utf-8`, кеш `seo:sitemap:*` на час. Индекс ссылается на все семь разделов.
- `/city/*`, `/venue/*`, `/category/*`, `/organizer/*`, `/artist/*` НЕ добавлять в `PreventIndexingOfServicePages::SERVICE_PATHS` (allow-by-omission). Закреплено `assertHeaderMissing('X-Robots-Tag')`.
- CRUD для категорий в проекте НЕТ; организацию создаёт установщик (`InstallerController::bootstrapOrganization()`, slug из названия). Артистов сводит `artists:sync` из свободного текста `event_artists` — пока команда не запускалась, `artists` пуста и карта артистов пустая (это корректно).
- **Архивные блоки «прошедшие события» (пункт 10, сделано 2026-10-15; частично снимает B-4).**
  Список посадочной уже показывал `completed` (`publiclyVisible()`), но вперемешку с предстоящими
  под одним заголовком «Мероприятия» — для страницы события нормально, для страницы
  артиста/площадки недопустимо (страница живёт годами, а не пока идёт ближайшая продажа).
  Разделение: `LandingPage::pastItems()`/`upcomingItems()` через `array_filter` по флагу
  `past: bool` (не срез — SQL кладёт события без сеанса в конец), `LandingPageService::items()`
  ставит `past = $startsAt !== null && $startsAt->lessThan($now)`, `LandingPageRenderer` рисует
  условно: нет архива → одна афиша «Мероприятия» (минимальный diff для живых страниц); есть →
  «Ближайшие мероприятия» + «Прошедшие мероприятия», каждый блок пропускается если пуст. CSS:
  `.events--archive` (прозрачный фон, муть-цвет). **ItemList в JSON-LD не тронут** — разметка
  описывает страницу, а не один её блок; событие без сеанса НЕ считается архивом (нет даты →
  утверждать «прошло» = ложь). Тесты — `tests/Feature/Seo/LandingArchiveTest.php` (7), ключевой —
  `past_only page drops the upcoming block`. Seo: 223, Events: 46, ProductionSafety: 8 — зелёные.

## Серверный текст тела страницы события (P-20 — сделано)
- `EventPageController::bodyFallback()` собирает ДВА блока внутри `#app`: блок статуса
  (`statusBanner`, только терминальные статусы) и контентный (`contentFallback`, все).
  Vue при монтировании очищает контейнер — покупатель блоков не видит, краулер без JS видит.
- **Ровно один `<h1>` на страницу:** контентный блок печатает заголовок только когда
  блока статуса нет (у терминального заголовок несёт статус).
- Контент: `<h1>`, «Дата и время» (+ «всего сеансов: N»), «Площадка», лид, «Билеты от …»,
  ссылка на афишу. Лид — `short_description`, иначе `strip_tags(description)` + `limit(500)`.
  Формулировка нейтральная, НЕ «Ближайший сеанс» (`nearestSession()` для прошедшего даёт последний).
- Цена — из того же `$offer`, что JSON-LD. «Все билеты проданы» — только без блока статуса.
- **Перелинковка «событие → артист» (пункт 9, сделано).** В том же контентном блоке —
  `artistLinks()` → `<p class="seo-fallback__artists">Исполнители: <a href="{route('landing.artist')}">…</a></p>`
  (пустой список → пустая строка). Дублируется в витрине: `EventController::showBySlug()` отдаёт
  `data.artists=[{name,slug}]`, `EventPage.vue` рисует чипы обычным `<a href="/artist/{slug}">`
  (**НЕ `<RouterLink>`** — хеш-SPA не знает `/artist/*`). Источник — `Event::artists()`
  (`status='active'`, порядок `is_headliner desc, sort_order, name` на квалифицированных колонках).
  Без этой ссылки страницы артистов достижимы ТОЛЬКО через карту = вес не перетекает. Тесты —
  `tests/Feature/Seo/EventArtistLinkTest.php` (11).

## Осталось из аудита
- ~~**P-10a** — перенести карты на `/sitemap.xml`.~~ **СДЕЛАНО 2026-10-15.** Карты живут в
  `Seo/routes/web.php` (`/sitemap.xml` + 6 разделов), `Seo/routes/api.php` удалён, старые пути
  отвечают 301 через nginx. Ни один из двух OpenAPI карты НЕ описывал — править было нечего.
- P-10b (относительный `Sitemap:`) — сознательное отклонение сохранено, см. лог `2026-10-15.md`.
- **P-11** — hreflang: отложено до запуска EN.
- Остальные пункты (P-01…P-09, P-12…P-24) закрыты — см. лог `2026-10-11.md`, партии 1–7.

## Стратегия роста — `SEO-STRATEGY-2026-10-15.md`
Техфундамент закрыт и НЕ ограничитель трафика. Ограничитель — контент и авторитет:
- ~~**B-1 нет канонической сущности Artist.**~~ **СДЕЛАНО 2026-10-15.** Добавлены таблицы
  `artists` + `artist_event` (спец 012, Laravel-миграция `2026_10_15_000100_create_artist_tables`),
  модель `Events\Models\Artist`, команда `artists:sync`, страница `/artist/{slug}` и карта
  `sitemap-artists.xml`. `app/Models/EventArtist` (`event_id`/`name`/`stage`/`is_headliner`) остаётся
  записью ВНУТРИ события — это ИСТОЧНИК для команды, не сущность. Подробности — `2026-10-15.md`.
- ~~**B-2 редакционного слоя нет.**~~ **СДЕЛАНО 2026-10-15.** `Modules/Content`
   ожил: добавлен манифест `app/Modules/Content/module.json` (приоритет 140, требует `seo`),
  удалён из `UNREGISTERED_ALLOWED` в `tools/verify-module-structure.php`.
  Маршрут `/page/{slug}` зарегистрирован в `app/Modules/Content/routes/web.php`,
  подключён в `routes/web.php` ВЫШЕ `Route::fallback()` (после Seo, до Installer).
  Контроллер `PageController` + сервис `PageService` + рендер `PageRenderer` +
  Domain `PageDocument`. Опубликованная страница: `status='published'` И
  `published_at <= now()` (NULL = «нет даты — не опубликовано»); черновик и
  будущая дата → 404. JSON-LD `Article` (`headline`/`description`/`url`/
  `mainEntityOfPage`/`datePublished`/`dateModified`/`author`/`publisher`) +
  `BreadcrumbList` через `StructuredDataService::generateArticle()`. Имя
  маршрута `content.page` (для sitemap'а), адрес в карте — `/sitemap-pages.xml`.
  `PageDocument::canonical()` ОБЯЗАН быть обёрнут в `function_exists('route')`
  (purity-гейт иначе красный, `tests/Unit` без `vendor/` не загрузит класс).
  `safeJsonLd()` в `PageRenderer` — осознанная копия из `LandingPageRenderer`
  (при появлении ТРЕТЬЕГО потребителя — выделить в `Nabilet\Core\Support\JsonLd::escape()`).
  Рендер свой самодостаточный (без `dist/`), как у посадочных. Тесты —
  `tests/Feature/Content/PageControllerTest.php` (25), ключевые — `draft`/
  `future`/`null published_at` → 404, `sitemap addresses → 200`, описание
  режется `mb_substr` на 160 символах (UTF-8!), JSON-LD `Article` без пустых
  скаляров. Seo+Content: 248 — зелёные.
- **B-3** hash-роутинг (`resources/js/router/index.ts:104` `createWebHashHistory`) — каталог
  индексируется одним URL `/`; компенсируется серверными посадочными.
- B-5 авторитетность = 0; B-7 нет GSC/Вебмастера.
Порядок: ~~карта на `/sitemap.xml`~~ (сделано) → ~~сущность Artist~~ (сделано) →
~~перелинковка событие→артист (пункт 9)~~ (сделано) → ~~архивные блоки «прошедшие события»
на страницах артиста/площадки (пункт 10, частично снимает B-4)~~ (сделано) →
~~B-2 редакционные страницы CMS~~ (сделано) → B-7 измерение (GSC + Яндекс.Вебмастер,
`/sitemap.xml`, регионы) → ссылки партнёров.
