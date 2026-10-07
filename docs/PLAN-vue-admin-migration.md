# NABILET — переезд админки на Vue, выпил Filament, SEO по slug

> **Статус (2026-09-30): миграция полностью выполнена.** Filament удалён из проекта
> (нет пакетов `filament/*` в composer.json, нет `app/Filament/`, `app/Providers/Filament/`,
> blade-шаблонов панели; `/admin` отдаёт Vue-админку). Витрина и CRUD-админка работают через API.
> Это исторический документ; актуальные статусы и остаток работ — только в `docs/ROADMAP.md`.

## Решение (пользователь, 2026-09-24)
- Пишем админку на Vue (полностью).
- Filament убран (✅ выполнено).
- Моки убираем — всё на реальный Laravel API.
- SEO: страницы мероприятий — каждая со своим slug (не id).

## Исторический снимок (проверен 2026-09-24)

Этот файл — план миграции админки, а не текущий аудит. Состояние репозитория и
оставшиеся задачи сверять по [`PRODUCTION-READINESS.md`](PRODUCTION-READINESS.md)
и [`ROADMAP.md`](ROADMAP.md): в частности, `lib/mock.ts` уже удалён; старые
пункты «удалить mock.ts» ниже — исторические записи, не открытые задачи.

- Vue-витрина переведена на API, Filament удалён.
- Позднее добавлены реальные админ-страницы, редакторы почтовых шаблонов и
  исходящих вебхуков; полная актуальная карта — в production readiness документе.

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