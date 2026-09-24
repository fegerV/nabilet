# NABILET — переезд админки на Vue, выпил Filament, SEO по slug

## Решение (пользователь, 2026-09-24)
- Пишем админку на Vue (полностью).
- Filament убираем.
- Моки убираем — всё на реальный Laravel API.
- SEO: страницы мероприятий — каждая со своим slug (не id).

## Текущее состояние (проверено 2026-09-24)
- Laravel API почти готов: 10 модулей, CRUD events/sessions/venues/halls/inventory/orders/payments.
- **Витрина полностью на API**: Catalog, EventPage (по slug), SeatSelection (реальная схema зала),
  Checkout — всё через `lib/api.ts`. Моки в витрине убраны.
- **Холд/снятие/checkout работают**: атомарный decrement inventory, финализация sold, возврат при снятии.
- **SEO-страницы по slug работают**: `GET /event/{slug}` (301 на canonical с publicId) + JSON-LD.
- Vue-фронт админки (Dashboard/Events/Orders) читает `lib/mock.ts` — предстоит переключить на API.
- `lib/mock.ts` ещё импортируется админкой и HallEditor — удалить на этапе 4.
- Filament: провайдер, ресурсы, страницы, справка, чек-лист — всё удалить после переезда (этап 5).
- Пробел в API: Sessions и Venues — только GET (нет write-эндпоинтов) — добавить на этапе 1 ROADMAP.md.
- Авторизация админки: AuthController есть (login/logout/register) — нужен middleware ролей + токен (этап 1).

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
5. **Выпил Filament**:
   - vendor/filament (composer), app/Providers/Filament, app/Filament/
   - роуты /admin (заменить на Vue-админку), мидлвары, конфиг.
   - Перенести в Vue: справка, чек-лист перед публикацией, подсказки.
6. **Сборка/деплой на шаред** — npm run build → dist/, один ZIP с vendor/, установщик (уже есть Installer).

## Принципы
- Данные (залы/концерты) — в БД, фронт подтягивает через API, БЕЗ пересборки.
- Бибип build только при изменении кода фронта, не данных.
- Один интерфейс (Vue), Filament исчезает.