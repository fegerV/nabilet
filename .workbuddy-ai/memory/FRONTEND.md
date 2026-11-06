# NABILET — витрина, зал, покупка

Вынесено из `MEMORY.md`, чтобы тот не упирался в лимит инъекции. Общее — там.

## Выбор места
`CoordSeatMap`: чипы цен = легенда-фильтр; тап по чипу тянет масштаб к местам
категории (`focusTransform`), не из категории — opacity .22 и без зоны нажатия.
`circle.seat-hit` (прозрачный, под видимым кружком) — ЕДИНСТВЕННАЯ цель клика,
у видимого `pointer-events:none`. MAX_ZOOM 8. Детали → `2026-10-20.md`.

**Клики проверять только `Input.dispatchMouseEvent`** — `dispatchEvent('click')` по
`seat-hit` работает даже когда живой клик сломан (кружок сверху перехватывает).

## Раскладка покупки
`PurchaseWidget` — ВО ВСЮ ширину страницы (вне колонок карточки). На шаге выбора
сводки-колонки нет, итог — полоса ВНУТРИ рамки схемы (`data-summary`).

**SVG вписывается через `meet`: ширина рамки без роста высоты НЕ увеличивает зал**
(пустые поля по бокам) — высота полотна `min(70vh,660px)` на lg. Детали → `2026-10-22.md`.

## Импорт hallplan
`levels[].seats[]` — истина, конвертер переносит байт-в-байт
(`docs/YANDEX-AFISHA-HALLPLAN.md` §6.2). Координаты сверены с Яндексом: по секторам
масштаб РАВНОМЕРНЫЙ 1.15, ошибка <1 px.

Сторона сцены — ДАННЫЕ, не константа: `meta.stage_side` в `GET /inventory`
(`top|bottom`) → `CoordSeatMap`. В зале СурГУ ряд 1 у сцены ВНИЗУ.

## Цены
Только `PATCH /inventory/sessions/{id}/prices` (по ряду/стоячей зоне, не по месту);
`session_only:true` не трогает сетку зала (в UI включено по умолчанию).
UI `/admin/sessions/{id}/prices`; лесенка — `tools/vavilon-price-ladder.php`.

## Конфиг витрины
`GET /storefront` кешируется на 60 с по ключу `storefront-config:<orgId>`,
инвалидация — в `update()`/`reset()`. Тест — `StorefrontConfigTest`.

**«Сотрудник vs организация»**: `StaffRole` читает `user_roles` (`User::roles()`), а
`StorefrontController::organizationId()` — `user_organization` (belongsToMany).
Фикстура сотрудника пишет в ОБЕ таблицы, иначе конфиг уедет в глобальную строку.

## Прочее
- Оболочка страницы события — `Events\Http\EventPageShell` (`render()`+`path()`) +
  `Events\Http\EventPageContent` (тексты + площадка); контроллер 706 строк / 13 методов.
  Переносы механические (скрипты в `.tmp-hall/`, не в git): докблоки переехали байт-в-байт.
- OPcache в проде УЖЕ настроен (`Dockerfile:63,67` + `docker/php/opcache.ini`, финальная
  стадия `FROM base`). Его отсутствие в локальной сборке PHP — не повод что-то менять.
- «Ноль импортов» ≠ мёртвый код: `NBottomSheet`/`NStepper` описаны в `docs/UI-DESIGN.md`
  (у первого — открытый план №5). Проверять доки и планы прежде чем удалять компонент.
- Модули-заглушки (только `Providers/*ServiceProvider.php`, тестировать нечего):
  `AbTesting`, `Admin`, `Ai`, `Heatmaps`, `Localization`, `Telegram`.
