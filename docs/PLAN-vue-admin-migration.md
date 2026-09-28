# NABILET — переезд админки на Vue, выпил Filament, SEO по slug

> **Статус (2026-09-29): миграция выполнена, Filament удалён из проекта**
> (нет пакетов `filament/*` в composer.json, нет `app/Filament/`, `app/Providers/Filament/`,
> blade-шаблонов панели; `/admin` отдаёт Vue-админку). Актуальные статусы — в `docs/ROADMAP.md`.
> Ниже — исторический план и текущее состояние.

## Решение (пользователь, 2026-09-24)
- Пишем админку на Vue (полностью).
- Filament убран (✅ выполнено).
- Моки убираем — всё на реальный Laravel API.
- SEO: страницы мероприятий — каждая со своим slug (не id).

## Текущее состояние (проверено 2026-09-24)
- Laravel API почти готов: 10 модулей, CRUD events/sessions/venues/halls/inventory/orders/payments.
- **Витрина полностью на API**: Catalog, EventPage (по slug), SeatSelection (реальная схema зала),
  Checkout — всё через `lib/api.ts`. Моки в витрине убраны.
- **Холд/снятие/checkout работают**: атомарный decrement inventory, финализация sold, возврат при снятии.
- **SEO-страницы по slug работают**: `GET /event/{slug}` (301 на canonical с publicId) + JSON-LD.
- **Текущее состояние админки**: Vue-страницы (`pages/admin/`) читают API; остатки `lib/mock.ts` — только в `AdminDashboardPage.vue` и `TicketsPage.vue` (удалить на этапе 4 ROADMAP «Чистка»).
- Filament: ✅ удалён полностью — провайдер, ресурсы, страницы, справка, чек-лист (этап выполнен).
- Пробел в API закрыт (этап 1 ✅): Sessions и Venues получили write-эндпоинты (`POST/PATCH/DELETE` под `auth:sanctum, admin`).
- Авторизация админки ✅ (этап 1): Sanctum-токены + middleware ролей `EnsureAdminRole` (admin/manager).

## Документы
- `docs/ROADMAP.md` — статусы этапов, сверяться перед работой (источник истины).
- `docs/PLAN-vue-admin-migration.md` — этот файл (детали).
- `docs/openapi.yaml` — контракт API.

## Этапы
1. **API-клиент для Vue** — `lib/api.ts`: base fetch-обёртка с ошибками/baseUrl.
2. **Витрина на реальный API** — Catalog, EventPage, SeatSelection, Checkout, Tickets, Payment.
   Убрать `mock.ts` из импортов.
3. **SEO-страницы мероприятий по slug**:
   - Laravel: `GET /event/{slug}` и `GET /event/{slug}/seats` → отдают SPA-обёртку
     + `<title>`, meta description, og-теги, JSON-LD (для индексации).
   - Vue: роуты `event/:slug`, `event/:slug/seats`; fetch по slug в API.
   - Sitemap уже есть (sitemap-events.xml) — проверить что slug используется.
4. **Админка на Vue**:
   - CRUD страницы: Залы (конструктор схем уже есть — HallEditor.vue),
     Мероприятия, Сеансы, Площадки, Заказы, Платежи/Возвраты, Билеты, Пользователи.
   - Авторизация: /api/v1/auth (есть AuthController).
   - Права: роли (admin/manager) — на бэкенде.
5. **Выпил Filament** — ✅ выполнено:
   - vendor/filament (composer), app/Providers/Filament, app/Filament/ — удалены
   - роуты /admin заменены на Vue-админку; Filament-мидлвары/конфиг удалены
   - Справка/чек-лист Filament удалены вместе с панелью; подсказки — в Vue-формах
6. **Сборка/деплой на шаред** — npm run build → dist/, один ZIP с vendor/, установщик (уже есть Installer).

## Принципы
- Данные (залы/концерты) — в БД, фронт подтягивает через API, БЕЗ пересборки.
- Бибип build только при изменении кода фронта, не данных.
- Один интерфейс (Vue), Filament исчезает.