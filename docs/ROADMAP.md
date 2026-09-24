# NABILET — Roadmap: Vue-админка, выпил Filament, SEO по slug

> Источник истины для продвижения. Сверяться с этим файлом перед каждым этапом.
> Пометки: `[x]` — сделано и проверено, `[ ]` — предстоит. Дата обновления — внизу.

## Дерево проекта

```
C:\Project\nabilet
├─ app/Modules/            ← 11 модулей Laravel, API почти готов
├─ resources/js/           ← Vue 3 + Vite + Pinia
│  ├─ lib/                 ← api.ts (клиент), inventory.ts, hall.ts, mock.ts (УДАЛИТЬ)
│  ├─ components/ui/       ← свой UI-кит: NButton, NInput, NDataTable, NModal...
│  ├─ components/seat/     ← SeatMap, SeatLegend, OrderSummary
│  ├─ pages/storefront/    ← витрина (Catalog, Event, SeatSelection, Checkout...)
│  ├─ pages/admin/         ← Vue-админка (Dashboard, Events, Orders — на моках)
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
- Моки в витрине убраны; `mock.ts` ещё используется админкой и HallEditor. ⚠️
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
  - [ ] Список залов (AdminHallsPage) + переход в редактор по клику (сделано, проверить визуально)
- [ ] **Перенести из Filament**: страница справки, чек-лист перед публикацией, подсказки в формах.

### Этап 3. Выпил Filament
- [ ] Убрать `vendor/filament` из composer + `composer remove filament/*`
- [ ] Удалить `app/Providers/Filament/`, `app/Filament/`
- [ ] Заменить роут `/admin` на Vue-админку; удалить Filament-профили/мидлвары
- [ ] Удалить blade-шаблоны Filament: help-docs, widgets, event-edit
- [ ] Проверить, что `/admin` открывает Vue-приложение
- [ ] `php artisan test` — все тесты зелёные

### Этап 4. Чистка и релиз
- [ ] Удалить `lib/mock.ts` полностью (никто не импортирует)
- [ ] Проверить бандл: нет ли в admin-чанках моковых данных
- [ ] Деплой-пакет: `npm run build` → dist/, ZIP с vendor/, установщик
- [ ] На шаред-хостинге: проверить API, SPA, SEO-страницы, sitemap

## Текущие эндпоинты API (по модулям)

| Модуль | Роуты (относительно /api/v1) | CRUD? |
|---|---|---|
| Auth | POST /login /logout /register /forgot-password /reset-password /verify-email | + |
| Cart | GET /, POST /items, DELETE /items/{id}, POST /checkout | + |
| Events | GET /events, GET /events/{event}, GET /events/by-slug/{slug}, POST /events, PATCH /events/{event}, PUT /events/{event}, DELETE /events/{event} | ✅ |
| Inventory | GET /inventory, GET /inventory/{item}, GET /sessions/{sessionId}/availability | частично |
| Orders | GET /orders, GET /orders/{order}, POST /orders, POST /orders/{order}/cancel | ✅ |
| Payments | GET /payments, GET /payments/{p}, POST /payments, POST /payments/demo-pay, + webhooks | ✅ |
| Seo | GET /sitemap.xml, /sitemap-events.xml, /sitemap-static.xml, /sitemap-venues.xml | - |
| Sessions | GET /sessions, GET /sessions/{session} | ⚠️ нет write |
| Tickets | GET /tickets, GET /tickets/{t}, /history, /qr, POST /checkin/scan, /verify | + |
| Venues | GET /venues, GET /venues/{venue}, POST /venues/{venue}/schemas, PUT/DELETE /venues/schemas/{schema} | ⚠️ нет write venues |
| Webhooks | POST /webhooks/{type}, /webhooks/payment/{provider} | + |

## Принципы (не нарушать)
1. Данные — в БД, фронт тянет через API. Никаких моков в проде.
2. `npm run build` → dist/ (Vite `--config vite.config.ts`); пересборка только при изменении кода.
3. Один интерфейс (Vue); Filament исчезает полностью.
4. SEO: каждая страница события — свой slug, серверная обёртка + JSON-LD.
5. Свой UI-кит (components/ui) — не подключать Vuestic/Geeker/Element Plus.
6. Ошибки API — `{error:{code,message,errors?}}`; 409 = конфликт (место занято).

---

_Обновлено: 2026-09-24. Статус: Этап 1 в работе (CRUD Sessions/Venues)._
