# Аудит конструктора электронных билетов

Дата: 2026-11-26. Ветка: `fix/ticket-builder-audit` (создана от `main`, PR по зальному редактору не затронут).

## 1. Подтверждённые дефекты и исправления

| ID | Приор. | Что gefunden | Исправление | Тест |
|---|---|---|---|---|
| T-01 | P1 | PDF/PNG печатали `IMG` и `|| || ||` вместо изображения и штрихкода | `imageElement()` читает только медиаресурс своей организации, `Code128Encoder` строит настоящий CODE128-B SVG; legacy `/storage/…` поддерживается | `TicketTemplateContractTest::test_pdf_html_renders_real_barcode...` |
| T-02 | P1 | rotation/opacity работали в-editor и в кабинете, но игнорировались в PDF/PNG | общая обёртка `wrapTransform()` для всех типов элементов | там же |
| T-03 | P1 | Размер холста имел два источника: `template_json` и колонки `ticket_templates` | `TicketTemplateConfig v1.0` нормализует всё в `canvas`; контроллер синхронизирует колонки; кабинет читает тот же конфиг | `test_canvas_inside_template_json_is_the_only_size_source` |
| T-04 | P1 | Круг игнорировал `r`, линия читала `x/y` и не знала `x1/y1`, QR — `size` | единая геометрия `x/y/width/height/r/x1/y1/x2/y2/cx/cy/z/rotation/opacity` | `test_pdf_html_renders...`, `danceZone`-аналоги |
| T-05 | P1 | Три независимые карты переменных, неизвестный плейсхолдер печатался покупателю как `{{...}}` | `TicketVariableResolver` (PHP) + расширенный `flattenVariables` (TS); неизвестные → пустая строка | `test_pdf_html_renders...`, Vitest `ticketBuilder.test.ts` |
| T-06 | P1 | Двойной символ валюты, внутренний ID заказа вместо публичного номера | `Money::format()` — единственный владелец цены; `order.number` — человекочитаемый номер; обновлён дефолтный шаблон | `TicketPriceFormatSingleOwnerTest`, `CurrencySymbolSingleOwnerTest` |
| T-07 | P1 | Серверная валидация шаблона проверяла только размеры и число элементов | `TicketTemplateConfig::validate()`: версия, типы, уникальные id, границы геометрии, opacity, QR ≥ 60 px, лимиты, 2 МБ JSON; код `TICKET_TEMPLATE_INVALID` + id объектов | `test_strict_validation_rejects...` |
| T-08 | P1 | Ctrl+Z/Ctrl+Y перехватывались внутри textarea/input и отменяли весь макет | глобальный обработчик игнорирует form controls и contenteditable | существующие mount-тесты + новый код |
| T-09 | P1 | Undo/redo сливал быстрые правки из-за debounce 350 мс и терял выделение | снимок при каждом завершённом действии, `selectedId` в снимке | Vitest ( undo/redo ) |
| T-10 | P1 | Уход со страницы/закрытие вкладки теряли правки | `isDirty` + `onBeforeRouteLeave` + `beforeunload` | код + планы E2E |
| T-11 | P1 | Два администратора могли молча перезаписать шаблон | обязательный `base_updated_at` на PUT/PATCH, 409 `TICKET_TEMPLATE_CONFLICT`, понятное сообщение в конструкторе | `test_update_requires_a_fresh_base_updated_at` |
| T-12 | P1 | Серверного предпросмотра не было; печать шла через `window.print()` | `GET /api/v1/ticket-templates/{id}/preview?format=html|png|pdf`, тот же рендерер, метка «ОБРАЗЕЦ», заведомо недействительный QR | `test_preview_endpoint_uses_the_same_renderer...` |
| T-13 | P1 | Отключённый назначенный шаблон продолжал оформлять билеты | `TicketTemplateResolver::assignedTemplate()` проверяет `active` | `test_disabled_assigned_template_is_not_used_for_tickets` |
| T-14 | P1 | `exec()` без тайм-аута, очистка профиля Chrome нерекурсивная | `Symfony Process` с тайм-аутом и `finally`, рекурсивная очистка temp/профиля | код; load-тест не проводился |
| T-15 | P2 | Произвольный URL картинки из `template_json` мог уйти во внешний запрос | канонический конфиг допускает только `media_id` или `/storage/…`, рендерер читает байты локально | код + валидатор |
| T-16 | P2 | OpenAPI не описывал шаблоны и предпросмотр | добавлены 4 операции, `TicketTemplateConfig`, `TicketTemplateWriteRequest`, `TemplateId`; обе копии синхронизированы | `verify-openapi.php` 11/11, `verify-spec-parity.php` 6/6 |

## 2. Единая модель

`app/Modules/Tickets/Domain/TicketTemplateConfig.php` — версия `1.0`, `canvas {width,height,unit}`, `background {color,gradient}`, `elements[]` со стабильным `id`, `z`, всеми координатами, `rotation`, `opacity`, `visible`, `when`,typography, `media_id`, `data`. Legacy-шаблоны (поля `width/height/backgroundColor/backgroundGradient` и разрозненные element.*) нормализуются при чтении; несколько источников размеров больше нет.

`app/Modules/Tickets/Domain/TicketVariableResolver.php` и `resources/js/lib/ticketBuilder.ts` используют один и тот же набор имён переменных (30 ключей), включая venue/organizer/sector/zone/order.number/barcode/age/rules.

## 3. Верификация

| Проверка | Результат |
|---|---|
| `php -d memory_limit=1G vendor/bin/phpunit` | 1459 тестов / 6354 утверждений, 0 падений |
| `php -d memory_limit=1G tests/run.php` | 1094 метода / 2806 утверждений, 0 падений |
| `npm run test -- --run` | 593 теста, 0 падений |
| `npm run typecheck`, `npm run build` | успешно |
| `vendor/bin/phpstan analyse` | `No errors` |
| OpenAPI (обе копии) и parity | PASS |

## 4. Оставшиеся ограничения

1. **Нет снимка шаблона на момент выпуска билета.** Билеты резолвят активный шаблон в момент рендера. Изменение/удаление шаблона меняет оформление уже проданных билетов. Требуется миграция (snapshot JSON на `tickets`/`order_items`) и решение, какие данные фиксировать, а какие актуализировать при переносе мероприятия. Не сделано: меняет модель данных и бизнес-правила.
2. **Playwright/визуальные регрессионные тесты не добавлены.** В проекте нет инфраструктуры; нет скриншотов до/после и проверки PDF по числу страниц/встроенным изображениям в CI.
3. **QR декодер в автоматических тестах не подключён.** Локально доступен `cv2.QRCodeDetector`, но в репозитории testing-утилиты нет; golden-master проверяет матрицу, а не чтение готового PNG/PDF.
4. **Контролёр не принимает штрихкод как пропуск.** Штрихкод информационный, CODE128-B документирован; заявление о «входе по штрихкоду» не делается.
5. **Рефакторинг `TicketBuilder.vue` (3012 строк) не выполнен.** Выделение Toolbar/ElementsPanel/… требует параллельного E2E-покрытия, чтобы не потерять светлую/тёмную тему и жесты.
6. **XSS/CSS на стороне витрины** частично закрыт нормализацией шаблона на сервере, но существующие сохранённые шаблоны с внешними `src` в кабинете всё ещё рендерятся как есть до миграции данных.
7. **Билет с отсутствующим QR в БД** по-прежнему возможен при прямых записях: сервис выпуска подписывает всегда, но CHECK в БД нет.

Ни один пункт не отмечен как решённый без теста или изменения кода.
