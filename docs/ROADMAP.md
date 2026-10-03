# NABILET — Roadmap: Vue-админка, выпил Filament, SEO по slug, Метрика для Директа

> Источник истины для продвижения. Сверяться с этим файлом перед каждым этапом.
> Пометки: `[x]` — сделано и проверено, `[~]` — сделано частично, `[ ]` — предстоит. Дата обновления — внизу.

## Дерево проекта

```
C:\Project\nabilet
├─ app/Modules/            ← 11 модулей Laravel, API почти готов
├─ resources/js/           ← Vue 3 + Vite + Pinia
│  ├─ lib/                 ← api.ts (клиент), inventory.ts, hall.ts, metrika.ts (Метрика→Директ), mock.ts (остаточные импорты — убрать)
│  ├─ components/ui/       ← свой UI-кит: NButton, NInput, NDataTable, NModal...
│  ├─ components/seat/     ← SeatMap, SeatLegend, OrderSummary
│  ├─ pages/storefront/    ← витрина (Catalog, Event, SeatSelection, Checkout...)
│  ├─ pages/admin/         ← Vue-админка (Dashboard, Events, Sessions, Venues, Halls, Orders)
│  └─ router/              ← hash-режим
├─ dist/                   ← npm run build → сюда (Vite, outDir=dist)
└─ docs/
   ├─ PLAN-vue-admin-migration.md  ← детальный план (этапы 1-6)
   ├─ ROADMAP.md                   ← этот файл (статусы)
   └─ openapi.yaml                 ← контракт API
```

## Статус: ВИЗИТКА

- Витрина переведена на API: Catalog, EventPage, SeatSelection, Checkout. ✅
- SEO-страницы по slug: `GET /event/{slug}` + `GET /event/{slug}/{publicId}` + JSON-LD. ✅
- Холд/снятие/checkout работают через API (атомарный decrement, финализация в sold). ✅
- Моки в витрине убраны полностью; `mock.ts` остался в двух импортах вне витрины (`AdminDashboardPage.vue`, `TicketsPage.vue`) — убрать на этапе 4. ⚠️
- API-клиент `lib/api.ts` (baseUrl, ошибки, токен) — готов. ✅

## Roadmap (этапы)

### Этап 1. Подготовка API к админке
- [x] Список роутов по модулям — задокументирован ниже.
- [x] **CRUD-эндпоинты в Sessions** — добавлены:
  - [x] `POST /api/v1/sessions`, `PATCH /api/v1/sessions/{session}`, `DELETE /api/v1/sessions/{session}`
  - [x] Валидация (event/hall/схема exists, статусы), timezone по умолчанию, boot-хук public_id
- [x] **CRUD-эндпоинты в Venues** — добавлены:
  - [x] `POST /api/v1/venues`, `PATCH /api/v1/venues/{venue}`, `DELETE /api/v1/venues/{venue}`
  - [x] Авто-slug из name (с уникальностью), boot-хук public_id
- [x] **Авторизация для админки** — Sanctum:
  - [x] Установлен laravel/sanctum 4.3 (не было!), миграция personal_access_tokens
  - [x] `User` → трейт HasApiTokens; роли через `roles()` (таблица user_roles, не user_role — было неверно)
  - [x] AuthResource отдаёт `roles: ['admin'|'manager'|'support']`
  - [x] Middleware `admin` (EnsureAdminRole): админ/менеджер — можно, саппорт/аноним — 401/403
  - [x] Защищены write-роуты: events/store|update|destroy, venues/*(write), sessions/*(write), orders/{id}/cancel
  - [x] Проверено: без токена 401, admin 201, manager 201, support 403
- [x] **CRUD Halls** — добавлены (были только GET):
  - [x] POST /api/v1/halls, PUT/DELETE /api/v1/halls/{publicId} — уже существовали, но под мёртвым `auth:api, role:admin` → переведены на `auth:sanctum, admin`
  - [x] boot-хук public_id, status по умолчанию 'active' (БД NOT NULL)

### Этап 2. Админка на Vue (свой UI-кит)
- [x] **Авторизация**: страница `/#/admin/login` (AdminLoginPage), guard на /admin (без токена → login?redirect=), вход по Sanctum, сохранение ролей, кнопка «Выйти» в AdminShell
- [x] **Мероприятия** (AdminEventsPage) — переведён с mock.ts на API: список из `/api/v1/events`, фильтр по статусу, поиск, заполняемость по сеансам
- [ ] **Layout админки** (AdminShell): сайдбар уже есть (Залы, Мероприятия, Сеансы, Площадки, Заказы...) — подключить реальные страницы вместо placeholder
- [x] **CRUD Залы/Площадки** (AdminVenuesPage): список из `/api/v1/venues`, модалка-форма (название, город, регион, страна, адрес, статус), создание/редактирование по клику на строку, удаление через API. Исправлен авто-org_id (User::organizations без withTimestamps — колонки updated_at нет)
- [x] **CRUD Мероприятия** (AdminEventFormPage): страница-форма создания/редактирования (title, slug-авто, описание, статус, даты, валюта, постер, SEO). Создание → редирект на редактирование (isEdit computed/watch). Фиксы: FormRequest authorize (can('create') всегда false — нет EventPolicy → роль-проверка), boot-хук public_id в Event, даты не null (валидация date не принимает null)
- [x] **CRUD Сеансы** (AdminSessionsPage): список из `/api/v1/sessions`, модалка-форма (мероприятие, зал, дата, время, статус), создание/редактирование по клику. Фиксы: venue_id/schema_version_id NOT NULL (берутся из зала: venue зала + последняя published схема), GET /halls (indexAll), openEdit (row.raw), kind="session" в NStatusBadge
- [x] **Заказы** (AdminOrdersPage): список из `/api/v1/orders` (пагинация), фильтр по статусу, поиск, деталка (модалка) с составом, кнопка «Отменить заказ» → POST /{order}/cancel. Checkout теперь создаёт Order (был TODO); `/orders` без org_id — все заказы (был пустой ответ); Cart::session()
- [x] **Импорт схем залов из Яндекс.Афиши** — `tools/import_yandex_hallplan.py` (будет расширяться на все площадки Сургута/ХМАО):
  - [x] hallplan API сеанса → CDN JSON (уровни: места/структурированные ряды) → наш формат (сектор/ряд/место/цена)
  - [x] Референсы: `docs/samples/hallplan-vavilon.json` (Вавилон: 2 сектора, 252 места, цены 2899–6999₽), `hallplan-ledovyi.json` (Ледовый дворец: 738 мест, 7200–15500₽)
  - [ ] Каталог площадок Сургута/ХМАО → заполнить все схемы
- [x] **Конструктор схем зала** — HallEditor.vue подключён к API (не на моках):
  - [x] Загрузка: `GET /api/v1/halls/{publicId}/schema-versions` (черновик или последняя published)
  - [x] Автосохранение черновика: `POST /api/v1/halls/{publicId}/schema-versions/draft` (каждые 500 мс после изменения)
  - [x] Публикация: `POST /api/v1/schema-versions/{id}/publish`
  - [x] Сервер: boot-хук public_id в HallSchemaVersion; убраны алиасы Halls\Models (Type-ошибки); триггер иммутабельности — schema_json::text
  - [x] Импорт схемы из JSON (кнопка «Импорт JSON»): загрузка файла Афиши/экспорта → конвертация в редактор → автосейв; поверх published создаётся новая версия (draft)
  - [x] **Конвертер в инвентарь**: `HallSchemaVersion::toInventoryFormat()` (канвас → rows, нормализация 60×40, числовые id), `InventoryService::generateFromSchema` (sectors → hall_rows → seats + inventory_items), генерация при создании сессии (SessionController::store). e2e: сессия с канвас-схемой → 2 сектора/3 ряда/6 мест/цены из канваса
  - [x] **Реальные площадки Сургута**: залиты через API и опубликованы — Клуб «Вавилон» (252 места, цены 2899–6999₽ совпадают со скриншотом Афиши), Ледовый дворец (738 мест: Сектор A=342, Стандартный 187+203, Танцпол=6), Филармония, Fusion. Сессии на залах → инвентарь генерится из схем (проверено: 252 и 738 мест)
- [x] **Список залов** (AdminHallsPage.vue) — страница существует, переход в редактор по клику работает
- [x] **Перенос из Filament завершён вместе с выпилом панели**: Filament-страницы (справка, чек-лист перед публикацией, подсказки в формах) удалены вместе с панелью; подсказки живут прямо в Vue-формах (`AdminEventFormPage.vue` и др.). При необходимости раздел справки заведётся как Vue-страница.

### Этап 3. Выпил Filament — ✅ выполнено
- [x] `vendor/filament` убран из composer (в `composer.json` нет пакетов `filament/*`)
- [x] `app/Providers/Filament/`, `app/Filament/` удалены (каталогов нет в дереве)
- [x] Роут `/admin` отдаёт Vue-админку (SPA-роуты `#/admin/*`, см. `resources/js/router`); Filament-профилей/мидлваров нет
- [x] Blade-шаблоны Filament (help-docs, widgets, event-edit) удалены (`resources/views/filament/` отсутствует)
- [x] `/admin` открывает Vue-приложение (`AdminShell.vue` + страницы `pages/admin/`)
- [ ] `php artisan test` — все тесты зелёные (проверять после изменений кода)

### Этап 4. Чистка и релиз
- [ ] Удалить `lib/mock.ts` полностью (никто не импортирует)
- [ ] Проверить бандл: нет ли в admin-чанках моковых данных
- [ ] Деплой-пакет: `npm run build` → dist/, ZIP с vendor/, установщик
- [ ] На шаред-хостинге: проверить API, SPA, SEO-страницы, sitemap

### Этап 5. Яндекс Метрика → Яндекс Директ — 🟡 в работе (сервер готов, фронт частично)

Зачем: атрибуция расходов Директа по реальным продажам билетов. Конверсии
строится на целях Метрики (`reachGoal`), главная цель — `purchase` с revenue;
Директ использует её в автостратегиях («оплата», «доля ADS»).

**Готово (коммит `0d8b417e`, 2026-09-30):**
- [x] Конфиг `config/metrika.php`: ID счётчика, ключ аутентификации (`&ct=`), безопасный режим, e-commerce, валюта, карта событий воронки → целей (`event_view`, `seatmap_open`, `seat_selected`, `checkout_started`, `payment_attempt`, `payment_success→purchase`, `payment_fail`)
- [x] Сервис `MetrikaSettings` (модуль Analytics): слияние env-config с переопределениями из таблицы `metrika_settings` — админ меняет счётчик/цели без деплоя
- [x] Миграция `2026_09_30_000200_create_metrika_settings_table.php` (key/value, значения JSON-кодированы)
- [x] Публичный эндпоинт `GET /api/v1/analytics/metrika/config` (`MetrikaController`) — SPA тянет конфиг до авторизации
  - [x] `publicConfig()` отдаёт только ID счётчика, цели и валюту — секреты в JS не попадают
- [x] Клиентская библиотека `resources/js/lib/metrika.ts`: ленивая загрузка тега `mc.yandex.ru` (не блокирует первый рендер, не грузится при `counter_id=0`), сохранение UTM-меток в localStorage + `setParams` (привязка заказа к кампании Директа), `trackEvent()` → `ym(id,'reachGoal',цель)` с `revenue/currency` для покупки
- [x] Инициализация в `main.ts`: `initMetrika()` + `trackPageView` в `router.afterEach` (SPA-hits)
- [x] Регистрация сервиса в `AnalyticsServiceProvider`

**Осталось:**
- [ ] `.env` / `.env.example`: переменные `YANDEX_METRIKA_*` ещё не добавлены (перепроверено 2026-10-02 — в файлах их по-прежнему нет; см. ниже чек-лист запуска)
- [~] Вызовы `trackEvent()` в страницах витрины: `SeatSelectionPage` (`seatmap_open`, `seat_selected`, `page_view`) — **проставлены (проверено по коду, 2026-10-02)**. Остальные точки не проставлены: `CheckoutPage` (`checkout_started`), `payment_attempt` / `payment_success` (+revenue) / `payment_fail` на оплате. Ядро `trackEvent` готово
- [ ] UI админки: страница редактирования `metrika_settings` (сейчас правится только SQL/env)
- [ ] В Метровке: создать цели с именами один-в-один из `config/metrika.php` → goals; включить «Таргетирование → Обмен данными с Директом»
- [ ] В Директе: пересоздать цели через чек-лист «Метрика + Директ»; для автостратегии ставить цель `purchase`
- [ ] Проверка consent/cookie-banner: счётчик не должен грузиться до согласия (если баннер будет включён)
- [ ] Пересобрать фронт: `npm run build` (изменились `main.ts`, добавлен `lib/metrika.ts`)

Переменные окружения (добавить в `.env`, шаблон — в `.env.example`):

```
YANDEX_METRIKA_COUNTER_ID=            # 0/пусто — интеграция выключена
YANDEX_METRIKA_COUNTER_AUTH=          # ключ &ct= при размещении на другом домене
YANDEX_METRIKA_COUNTER_TYPE=web       # web | hit
YANDEX_METRIKA_SAFE_MODE=false        # clickBeacon:false, WebVisor off
YANDEX_METRIKA_ACCURATE_TRACK=false
YANDEX_METRIKA_ECOMMERCE=true         # params.product/revenue для корзины и покупки
YANDEX_METRIKA_CURRENCY=RUB
```

## Текущие эндпоинты API (по модулям)

| Модуль | Роуты (относительно /api/v1) | CRUD? |
|---|---|---|
| Auth | POST /login /logout /register /forgot-password /reset-password /verify-email | + |
| Analytics (Метрика) | GET /analytics/metrika/config — публичный конфиг счётчика для SPA | read-only |
| Cart | GET /, POST /items, DELETE /items/{id}, POST /checkout | + |
| Events | GET /events, GET /events/{event}, GET /events/by-slug/{slug}, POST /events, PATCH /events/{event}, PUT /events/{event}, DELETE /events/{event} | ✅ |
| Inventory | GET /inventory, GET /inventory/{item}, GET /sessions/{sessionId}/availability | частично |
| Orders | GET /orders, GET /orders/{order}, POST /orders, POST /orders/{order}/cancel | ✅ |
| Payments | GET /payments, GET /payments/{p}, POST /payments, POST /payments/demo-pay, + webhooks | ✅ |
| Seo | GET /sitemap.xml, /sitemap-events.xml, /sitemap-static.xml, /sitemap-venues.xml | - |
| Sessions | GET /sessions, GET /sessions/{session}, POST /sessions, PATCH /sessions/{session}, DELETE /sessions/{session} (write — под `auth:sanctum, admin`) | ✅ |
| Tickets | GET /tickets, GET /tickets/{t}, /history, /qr, POST /checkin/scan, /verify | + |
| Venues | GET /venues, GET /venues/{venue}, POST /venues (write — `auth:sanctum, admin`), PATCH/DELETE /venues/{venue}, POST /venues/{venue}/schemas, PUT/DELETE /venues/schemas/{schema} | ✅ |
| Webhooks | POST /webhooks/{type}, /webhooks/payment/{provider} | + |

## Принципы (не нарушать)
1. Данные — в БД, фронт тянет через API. Никаких моков в проде.
2. `npm run build` → dist/ (Vite `--config vite.config.ts`); пересборка только при изменении кода.
3. Один интерфейс (Vue); Filament исчезает полностью.
4. SEO: каждая страница события — свой slug, серверная обёртка + JSON-LD.
5. Свой UI-кит (components/ui) — не подключать Vuestic/Geeker/Element Plus.
6. Ошибки API — `{error:{code,message,errors?}}`; 409 = конфликт (место занято).
7. Настройки интеграций (Метрика и т.п.) — на сервере (env + таблица `metrika_settings`), SPA получает их через API; секреты в публичный конфиг не попадают.

---

_Обновлено: 2026-10-02 (сверка с репозиторием). Статус: Этапы 1–3 закрыты (CRUD Sessions/Venues на API, Vue-админка работает, Filament выпит — в `composer.json` нет `filament/*`). Подтверждено по коду: конструктор залов и список залов подключены к API; Метрика — серверная часть (`MetrikaSettings`, таблица `metrika_settings`, контракт `GET /api/v1/analytics/metrika/config` в `docs/openapi.yaml`) и клиентское ядро (`lib/metrika.ts`) готовы (коммит `0d8b417e`); первые точки `trackEvent` появились на карте зала (`SeatSelectionPage.vue`: `seatmap_open`, `seat_selected`, `page_view`). Осталось: этап 4 — удалить `lib/mock.ts` (импортируется только в `AdminDashboardPage.vue` и `TicketsPage.vue` — перепроверено по коду) и собрать релизный пакет; этап 5 — env-переменные `YANDEX_METRIKA_*` (в `.env`/`.env.example` ещё нет), остальные точки воронки (корзина/checkout/purchase) в витрине, UI настроек `metrika_settings`, цели в Метрике/Директе. Тесты `php artisan test` в текущей песочнице не запускались (нет PHP) — отмечать зелёными только после фактического прогона. Фронтенд-тесты: `npm run test` (vitest) — 31 тест `tests/Unit/Components/HallEditor.test.js` **падают** с `ReferenceError: document is not defined`: у vitest нет конфига с `environment: 'jsdom'` (см. TESTING_GUIDE, раздел «Проблемы с окружением jsdom») — починить конфиг._
