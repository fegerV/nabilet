# Аудит готовности проекта NABILET Core

**Дата аудита:** 9 октября 2026
**Аудитор:** независимый Senior Software Architect (технический аудит)
**Репозиторий:** https://github.com/fegerV/nabilet (рабочая копия: `/workspace`, ветка с состоянием на 2026-10-09)
**Режим:** read-only аудит — код не изменялся, дефекты не исправлялись.

---

## 1. Методология и что фактически проверялось

### 1.1 Статический анализ (подтверждено чтением кода)

- `README.md`, `NABILET_Core_Technical_Spec_v1.md`, `Техническая спецификация разработки.md`, `use_cases.md`, `state-diagrams.md`, `docs/openapi.yaml`, `nabilet_core_spec/`, `docs/REVIEW-spec-bundle.md`, `Мысли.md`.
- Структура модулей: 35 модулей в `app/Modules/` (Events, Venues, HallSchemas, Inventory, Pricing, Cart, Orders, Payments, Tickets, Checkin, Notifications, Seo, Auth, Users, Admin, Organizations, Storefront, Embed, Analytics, Heatmaps, AbTesting, Ai, Backups, Content, Core, Installer, Localization, Media, Privacy, Security, Sessions, System, Telegram, Users, Webhooks).
- Все маршрутные файлы: `routes/api.php`, `routes/web.php`, `routes/console.php`, а также `app/Modules/*/routes/*.php`.
- Контроллеры и сервисы ключевых путей: `OrderController`, `OrderService`, `PaymentController`, `PaymentService`, `RefundService`, `TicketController`, `CheckinController`, `PromoCodeService`, `CartCheckoutService`, `OrderObserver`, `SitemapController`, `InstallerController`.
- Миграции (`database/`, `migrations.sql`), конфигурация (`config/`, `.env.example`, `docker-compose.yml`, `Dockerfile`), CI (`.github/workflows/ci.yml`).
- Frontend: `resources/js/pages/{storefront,admin}`, `resources/js/components`, контракты вызовов API из UI.

### 1.2 Динамические проверки — ФАКТИЧЕСКИ ЗАПУЩЕНЫ в данной среде

| Проверка | Команда | Результат |
|---|---|---|
| Синтаксический lint PHP | `php tools/lint.php` | **PASS** — 616 файлов, 0 ошибок |
| Ядро-тесты (без БД) | `php tests/run.php` | **PASS** — 684 теста, 1687 assertions, 0 failed. Внимание: сам раннер предупреждает «31 file(s) not run by this runner — run `php vendor/bin/phpunit`» |
| Фронтенд-тесты | `npx vitest run` | **PASS** — 18 files / 233 tests, все зелёные |
| OpenAPI-контракт | `php tools/verify-openapi.php` | **PASS** — 11 checks, 99 operations |
| Чистота домена | `php tools/verify-purity.php` | **PASS** — 113 files, 0 violations |
| Конверт ошибок §66 | `php tools/verify-error-envelope.php` | **OK** — 448 файлов |
| Контракт↔схема | `php tools/verify-contract-schema.php` | **PASS** — 28 entity schemas, 0 drift, 4 accepted |
| Прочие верификаторы | verify-route-ownership, verify-state-machines, verify-module-structure | Зелёные (выводы усечены, exit 0) |

### 1.3 Что НЕ удалось проверить (честно)

- **PHPUnit (Feature-тесты с БД)** — не запускался: требуется PHP ≥ 8.4 + MySQL; в среде недоступно. `verify-phantom-classes.php` упал на `vendor/composer/platform_check.php` (версия PHP ниже требуемой). Статус Feature-тестов — неподтверждён.
- **Docker-образ / docker-compose** — Docker в среде отсутствует; ни одна сборка образа не подтверждена.
- **Живые HTTP-сценарии E2E** (витрина → корзина → оплата → билет → чекин) — сервер не поднимался (нужны БД + миграции).
- **Реальная интеграция с ЮKassa** — нет ключей; webhook обрабатывается, но внешний контракт не проверен.
- **Отправка email/SMS** — SMTP-хост пустой в `.env.example` (`MAIL_HOST=`), отправка не проверялась.

Разделение: **[П]** = подтверждённый дефект (есть прямое доказательство в коде/исполнении); **[У]** = предположение (не проверено исполнением).

---

## 2. Сводная таблица по функциональным модулям

| # | Функциональный модуль | Ожидаемое поведение | Что обнаружено в коде | Доказательства: файлы, классы, методы, маршруты | Статус | Серьёзность | Что необходимо сделать |
|---|---|---|---|---|---|---|---|
| 1 | Каталог мероприятий / события | CRUD событий, публикация, публичная страница | Модуль Events с полным набором контроллеров/сервисов, маршруты опубликованных событий, витрина `/storefront` | `app/Modules/Events/routes/api.php`, `StorefrontController::show` (`app/Modules/Storefront/routes/api.php:17`), `resources/js/pages/storefront/*` | Готово (код) | — | E2E-проверка живым сервером [У] |
| 2 | Страницы событий (SEO-лендинги) | Публичные страницы с мета-тегами, JSON-LD | Страницы рендерятся SPA; SEO-модуль отдаёт sitemap; meta-теги формируются во фронтенде | `app/Modules/Seo/routes/api.php` (`sitemap.xml`, `sitemap-events.xml`, `sitemap-static.xml`) | Частично | Средняя | Проверить SSR/превью для краулеров; `dist/` не собран — индексация невозможна до сборки |
| 3 | Схемы залов | Импорт Yandex HallPlan, редактор, валидация | Модуль HallSchemas, Konva-редактор, импортёр, roundtrip-скрипт | `app/Modules/HallSchemas/`, `tools/import_yandex_hallplan.py`, `qa-scripts/hall-schema-roundtrip.py`, Vitest-тесты схем (зелёные) | Готово | Низкая | — |
| 4 | Места и inventory | Холды, блокировки, sold/available, CHECK-ограничения | Полный цикл холдов: SeatHold, HoldSweeper, state machine, sweeper в планировщике | `SeatHold` (`app/Modules/Orders/Models/SeatHold.php`), `HoldSweeper`, `Schedule::command('seats:clear-expired')->everyMinute()` (`routes/console.php:24`) | Частично | Средняя | Нет admin-эндпоинтов block/unblock конкретных мест — маршрут отсутствует во всех `routes/*.php` [П] |
| 5 | Цены и тарифы | Многосоставные цены, места-тарифы | Модуль Pricing, сервисы цен; тесты ядра проходят | `app/Modules/Pricing/`, `tests/` (Pricing-сьюты в 684 PASS) | Готово (код) | — | Feature-тесты не запускались [У] |
| 6 | Корзина | Гостевая корзина по X-Cart-Token, холды, промокод | Реализовано: CartController, CartCheckoutService, скоупинг по токену | `app/Modules/Cart/Http/Controllers/CartController.php`, `CartCheckoutService.php`, комментарии о X-Cart-Token в `Tickets/routes/api.php` | Готово | Низкая | — |
| 7 | Заказы | Создание из корзины, машина состояний, отмена, автоистечение | OrderController/OrderService/OrderStateMachine; cancel релизнит inventory и снимает холды; StaleOrderExpirer | `app/Modules/Orders/routes/api.php:21-27`, `OrderService::cancelOrder` (стр. 180–230), `OrderObserver` | Готово | Низкая | — |
| 8 | Оплата (ЮKassa) | Инвойс, webhook, статусы; демо-режим | PaymentController store/show/webhook/demoPay; реальный провайдер YooKassaProvider; registry | `app/Modules/Payments/routes/api.php` (`POST /payments`, `webhooks/{provider}`, `demo-pay`), `Providers/YooKassaProvider.php` | Готово | Низкая | В проде обязательна подпись webhook; live-проверка невозможна без ключей [У] |
| 9 | **Возвраты (refunds)** | Покупатель/админ инициирует возврат, деньги уходят провайдеру, статус refunded | **Сервис полностью реализован** (RefundService→YooKassaProvider::refund, RefundStateMachine, делегат `PaymentService::refundPayment`), **но ни один HTTP-маршрут его не вызывает и в UI нет кнопки**. Админская отмена (`POST /orders/{order}/cancel`) освобождает места, но **не делает возврат денег**, вопреки тексту подтверждения в UI («Если он оплачен — будет инициирован возврат») | `RefundService::refund` (`app/Modules/Payments/Services/RefundService.php:43`); `grep Route::.*refund` — единственное совпадение в `RequirePermission.php:17` (пример в docblock); callers `refundPayment` — только `tests/Feature/Payments/PaymentWritePathTest.php`; `AdminOrdersPage.vue:130` confirm-текст vs `OrderService::cancelOrder` (без возврата) | **Частично (бэкенд есть, вход нет)** | **Критическая** | Добавить `POST /orders/{order}/refund` (+ permission `orders.refund`), вызов RefundService из cancel для paid-заказов, кнопку в UI, клиентский возврат в «Мои билеты» |
| 10 | Билеты (выпуск, QR) | Выпуск при оплате, QR, история, «мои билеты» | TicketService внедряется жёстко (исправлена ловушка default-null); QR/history/my-tickets доступны | `PaymentService::__construct` комментарий стр. 57–66; `Tickets/routes/api.php` (`/tickets/{ticket}/qr`, `/history`, `/my-tickets`) | Готово | Низкая | — |
| 11 | **Билет PDF** | `GET /tickets/{ticket}/pdf` по контракту | **Эндпоинт отсутствует.** В openapi.yaml операция описана, в Laravel-маршрутах — нет; в комментариях маршрутов это признано | `docs/openapi.yaml:516` (`/api/v1/tickets/{ticket}/pdf`); `grep pdf app/Modules/Tickets/routes/api.php` — только комментарий стр. 43 | **Не реализовано** | Высокая | Реализовать генерацию PDF (tmpl-шаблоны уже есть) или вырезать операцию из контракта |
| 12 | Чекин (онлайн) | Скан/верификация билета, гашение | CheckinController scan/verify закрыты `auth:api + admin`; логика CheckinEvaluator есть | `Tickets/routes/api.php` (checkin/scan, checkin/verify); `app/Modules/Checkin/Domain/CheckinEvaluator.php` | Частично | Средняя | Нет guard'а `checkerAuth`/device-token — признано в комментарии самого маршрутного файла; сотрудник доступен только через admin-сессию |
| 13 | **Чекин: устройства и offline-бандлы** | Регистрация чекер-устройств, выдача/синхронизация офлайн-пакетов, sync offline scans | **Domain-слой написан (OfflineBundleBuilder и др.), но ни контроллеров, ни маршрутов, ни Android-интеграции нет.** В openapi.yaml 4+ операции описаны | `app/Modules/Checkin/Domain/` (9 файлов, нет Controller/routes); `docs/openapi.yaml:685,1358,1393,1838,1879`; grep `devices|offline-bundles` по `routes/*.php` — пусто | **Не реализовано (только контракт+домен)** | Высокая | Реализовать checkerAuth guard, controllers, routes; связать с `android/` |
| 14 | Личный кабинет покупателя | Заказы, билеты, настройки, возвраты | «Мои билеты» и заказы доступны (auth + гостевой токен) | `/my-tickets` (guest token), `GET /orders`, `GET /tickets` | Частично | Средняя | Возврат из кабинета невозможен (см. №9); OAuth-входа нет (см. №20) |
| 15 | Администрирование | Дашборды, CRUD событий/заказов/платежей/шаблонов | 10+ admin-страниц Vue, middleware `admin`, permission-класс | `resources/js/pages/admin/Admin*Page.vue` (Orders, Payments, Tickets, Analytics, NotificationTemplates…), `app/Core/Http/Middleware/RequirePermission.php` | Готово | Низкая | — |
| 16 | Уведомления (email/SMS/push) | Письма о заказе/билете/возврате, шаблоны | OrderObserver ловит все переходы статусов и рассылает; шаблоны настраиваются в UI; очередь database | `app/Modules/Orders/Observers/OrderObserver.php`, `AdminNotificationTemplatesPage.vue`, `QUEUE_CONNECTION=database` (`.env.example:39`) | Частично | Высокая | SMTP не настроен (`MAIL_HOST=` пусто, `.env.example:136`); worker очереди и cron нигде не запускаются автоматически — письма реально не уйдут без деплой-настройки [П по конфигурации] |
| 17 | Промокоды | Валидация, применение в корзине, лимиты | Полный CRUD + validate-эндпоинт + применение в checkout | `app/Modules/Pricing/routes/api.php:16-36`, `PromoCodeService.php`, `CartCheckoutService.php` | Готово | Низкая | — |
| 18 | Скивки/ценообразование | Динамика, сегменты | Pricing-модуль; дополнительно AbTesting/Heatmaps существуют как модули | `app/Modules/Pricing/`; тесты ядра PASS | Готово (код) | — | Живое поведение не проверялось [У] |
| 19 | SEO | Sitemap, robots, meta | Sitemap-маршруты есть | `app/Modules/Seo/routes/api.php` | Частично | Средняя | Сборка `public/build` отсутствует — продакшн-сайт не отдаёт статику до `npm run build` [П] |
| 20 | **OAuth / соцсети** | Вход через VK/Yandex/Google по ТЗ | Реализации нет: в `app/Modules/Auth` grep `oauth` — 0 совпадений (совпадения только в Backups/Users-identity, не относятся) | `grep -rli oauth app --include="*.php"` → `Backups/…`, `Users/Domain/UserIdentity.php` | **Не реализовано** | Средняя | Либо реализовать, либо актуализировать документацию |
| 21 | **Telegram-бот** | Покупка/уведомления в TG | Модуль — пустой каркас: только ServiceProvider + module.json, ни одного файла логики/маршрутов | `find app/Modules/Telegram -type f` → 2 файла | **Не реализовано (каркас)** | Средняя | Убрать из документации об «готовности» или реализовать |
| 22 | Локализация | Мультиязычность RU/EN | Модуль Localization + `lang/` существуют; эндпоинтов переводов в API нет | `app/Modules/Localization/`, `lang/`; grep `translations` по `routes/*.php` — пусто | Частично | Низкая | Решить: UI-i18n достаточно или нужен API |
| 23 | Аналитика | Дашборды, funnel | Маршруты Analytics подключены; страница есть | `routes/api.php:69` (Analytics/routes), `AdminAnalyticsPage.vue` | Частично | Низкая | End-to-end данных не проверялся [У] |
| 24 | Установка системы (Installer) | Веб-инсталлятор, миграции, сиды | Blade-страница + контроллер + web-маршруты | `app/Modules/Installer/{routes/web.php, InstallerController.php, install.blade.php}` | Готово (код) | Низкая | Не выполнялся живьём [У]; `qa-scripts/step2_migrate_run*.log` показывают успешные прогоны миграций в прошлом |

---

## 3. Заглушки, TODO, фиктивные данные, временные решения — фактический поиск

- **TODO/FIXME/XXX/HACK в исходниках приложения: 0** (3 совпадения — ложные: маски телефона/docblock) [П]. Подтверждено: `grep -rn "TODO|FIXME|XXX|HACK" app resources/js routes database` → только `RedactSensitiveData.php:52,56` и `Order.php:21` (формат order_number).
- **Фиктивные данные:** осознанный демо-режим платежей `PAYMENT_DEMO_MODE=true` прямо в `.env.example:106` + симулятор `POST /payments/demo-pay` (`Payments/routes/api.php`). Это рабочее по дизайну решение, но опасный дефолт при копировании env в прод [П].
- **Заглушек-«пустышек» в контроллерах не найдено**; при этом «мертвые», несвязанные куски: RefundService (нет входа, см. №9), Checkin Domain (нет transport-слоя, см. №13), Telegram-модуль (пустой каркас).
- **Конструктор шаблонов билетов ранее был недостижим** (UI звал `/api/ticket-templates` без префикса) — судя по комментариям, исправлено добавлением маршрутов; теперь reachable [П по коду].

## 4. Рассогласования модулей и документации (несогласованности)

1. **Docs vs code:** `PRODUCTION-READINESS`/README утверждают наличие Auth-заглушек/Embed-проблем — фактических заглушек в коде 0; документация устарела [П].
2. **OpenAPI vs Laravel-роуты:** контракт описывает ticket-PDF, checkin devices/bundles/sync — маршрутов нет (см. №11, №13). При этом `verify-openapi.php` PASS — значит верификатор сверяет синтаксис контракта, а не покрытие маршрутами [П].
3. **UI vs backend:** текст диалога отмены заказа обещает автоматический возврат денег; бэкенд `cancelOrder` возвращает только места [П].
4. **Android-модуль** (`android/`) существует, но серверных эндпоинтов устройств нет — офлайн-чекин непроходим ни с какой стороны [П].

## 5. Непроходимые критические сценарии (end-to-end)

| Сценарий | Проходимость | Причина |
|---|---|---|
| Выбрал место → оплатил → получил билет → скачал PDF | **Нет** | PDF-эндпоинт отсутствует [П] |
| Отменил оплаченный заказ → вернулись деньги | **Нет** | Cancel не вызывает RefundService; отдельного refund-маршрута нет [П] |
| Чекер зарегистрировал устройство → офлайн-скан → sync | **Нет** | Ни маршрутов, ни device guard [П] |
| Гость: корзина → оплата демо/провайдером → мои билеты | Да (код) | Проверено статически; живой запуск невозможен в среде [У] |
| Покупка через Telegram | **Нет** | Модуль пустой [П] |

## 6. Оценка общей готовности

**≈ 72–75%.**

Методика: 24 функциональные области (таблица §2) взвешены по коммерческой значимости (x3 — ядро продажи: события, схема, места, цены, корзина, заказ, оплата, билет; x2 — деньги и доступ на вход: возвраты, чекин, PDF, уведомления; x1 — периферия: SEO, локализация, TG, OAuth, аналитика, installer). Оценки: готово=1, частично=0.5, не реализовано=0. Взвешенная сумма даёт ~0.72–0.75. Ядро продаж (главный happy path) реализовано качественно: 684 ядерных теста + 233 фронтенд-теста зелёные, 0 TODO, верификаторы контрактов проходят. Потери сосредоточены в «втором эшелоне»: возвраты, PDF, офлайн-чекин, инфраструктура доставки писем.

## 7. Блокирующие запуск проблемы

1. **`public/build` отсутствует** — фронт не собирался в репозитории; без `npm run build` магазин не отображается [П].
2. **SMTP/queue/cron не сконфигурированы** (`MAIL_HOST=` пусто; QUEUE_CONNECTION=database требует `queue:work`; scheduler требует cron) — уведомления молча теряются [П по конфигу].
3. **`.env.example` содержит `PAYMENT_DEMO_MODE=true`** — риск выключить реальные платежи при деплое [П].
4. **Docker-образ ни разу не собран в этой среде; PHPUnit-сьют не выполнялся** — готовность к развёртыванию не подтверждена [У/факт среды].
5. Требуется PHP ≥ 8.4 — окружения ниже версии падают на platform_check (воспроизведено падением `verify-phantom-classes.php`) [П].

## 8. Десять наиболее важных незавершённых задач (приоритет)

1. `POST /orders/{order}/refund` + вызов RefundService из cancel для paid + кнопка в админке и кабинете (деньги!).
2. Исправить обманывающий UI-текст отмены заказа (или реализовать возврат — п.1).
3. `GET /tickets/{id}/pdf` — генерация билета по шаблону (контракт уже обязывает).
4. checkerAuth guard (device_token_hash) вместо admin-сессии для чекина.
5. CRUD устройств чекинга + выдача offline bundles + sync endpoint (домен готов, транспорта нет).
6. Интеграция `android/` с этими эндпоинтами.
7. Настройка боевого окружения: SMTP, queue worker, cron scheduler, HTTPS webhook secret.
8. Сборка `dist` + пайплайн деплоя статики (CI собирает только верификаторы).
9. Прогон PHPUnit Feature-сьюта на PHP 8.4 + MySQL и живой E2E happy-path.
10. Актуализировать README/PRODUCTION-READINESS: убрать claims о заглушках; честно пометить TG/OAuth/PDF/offline как «не реализовано».

## 9. Рекомендуемый порядок дальнейшего аудита

1. **Инфраструктура:** собрать образ Docker, поднять compose с MySQL, прогнать миграции + seeders, PHPUnit целиком.
2. **E2E happy path** (Playwright/httproute-тесты): витрина → холд → заказ → demo-pay → билет → QR → scan.
3. **Денежный контур:** идемпотентность webhook, частичные возвраты, расхождения сумм, конкурентные холды (stress).
4. **Контрактное покрытие:** написать верификатор «openapi operation ↔ зарегистрированный Laravel route» (сейчас такой дыры не видит ни один CI-step).
5. **Безопасность:** rate-limit гостевых токенов, перебор `X-Cart-Token`, подпись ЮKassa, права permission-матрицы.
6. **Нагрузочное** бронирование мест (CHECK-конкурентность) и SEO-краулинг после сборки статики.

---

### Приложение A. Команды, которые фактически выполнялись при аудите

```
php tools/lint.php                      → PASS (616 files)
php tests/run.php                       → PASS (684/684, 1687 assertions)
npx vitest run                          → PASS (233/233)
php tools/verify-openapi.php            → PASS (11 checks)
php tools/verify-purity.php             → PASS (0 violations)
php tools/verify-error-envelope.php     → OK (448 files)
php tools/verify-contract-schema.php    → PASS (0 drift)
php tools/verify-{route-ownership,state-machines,module-structure}.php → exit 0
php tools/verify-phantom-classes.php    → FAIL по среде (PHP < 8.4, platform_check)
```

Не запускались: PHPUnit (feature), docker build, живые HTTP/E2E, SMTP-отправка, реальная ЮKassa.

*Отчёт создан в режиме только-чтение; ни один файл приложения не изменён (добавлен лишь этот отчёт).*
